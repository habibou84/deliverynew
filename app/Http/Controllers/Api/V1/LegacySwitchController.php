<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Legacy\LegacyAccountsImporter;
use App\Support\PhoneNumber;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bascule depuis l'ancienne application : à la date choisie par l'administrateur, un e-commerçant ou un livreur qui
 * ouvre l'ancienne application (connecté) arrive sur Jibiat déjà connecté, sur son espace.
 *
 * L'ancienne application (module legacy/laravel-5.8/JibiatSwitch.php) demande au serveur, avec la clé de bascule, un
 * lien de connexion pour le compte ouvert ; avant la date, elle reçoit la date et laisse faire. Le lien porte un
 * ticket à usage unique valable deux minutes, échangé ici contre une session. Un compte absent de Jibiat (créé dans
 * l'ancienne application après la reprise) est repris à ce moment-là, avec sa fiche.
 */
class LegacySwitchController extends Controller
{
    /** Module à copier dans l'ancienne application */
    public const MODULE = 'legacy/laravel-5.8/JibiatSwitch.php';

    private const TICKET_SECONDS = 120;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->describe($request->user()->company)]);
    }

    /** Date de la bascule (passée ou présente : tout de suite) ; null l'annule */
    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate(['switch_at' => ['present', 'nullable', 'date']], [], ['switch_at' => 'date de bascule']);
        $company = $request->user()->company;
        $company->forceFill(['legacy_switch_at' => $data['switch_at'] ?? null])->save();

        return response()->json(['data' => $this->describe($company)]);
    }

    /** Nouvelle clé de bascule (affichée une seule fois) ; l'ancienne ne fonctionne plus */
    public function createKey(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $key = 'jbs_'.Str::random(40);
        $company->forceFill(['legacy_switch_key_hash' => hash('sha256', $key)])->save();

        return response()->json(['data' => [...$this->describe($company), 'key' => $key]], 201);
    }

    public function destroyKey(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $company->forceFill(['legacy_switch_key_hash' => null])->save();

        return response()->json(['data' => $this->describe($company)]);
    }

    public function module(): BinaryFileResponse
    {
        return response()->download(base_path(self::MODULE), 'JibiatSwitch.php', ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Demande de l'ancienne application pour le compte ouvert : la date si la bascule n'a pas commencé, sinon le
     * lien qui ouvre la session sur Jibiat.
     */
    public function ticket(Request $request, LegacyAccountsImporter $importer): JsonResponse
    {
        $key = (string) $request->bearerToken();
        $company = str_starts_with($key, 'jbs_')
            ? Company::query()->where('legacy_switch_key_hash', hash('sha256', $key))->first()
            : null;
        if ($company === null) {
            return response()->json(['message' => 'Clé de bascule invalide : créez-en une dans Réglages › Intégrations › Reprise de l\'ancienne application.'], 401);
        }
        $here = Tenancy::enabled() ? app(Tenancy::class)->company() : null;
        if ($here !== null && $here->id !== $company->id) {
            return response()->json(['message' => 'Cette clé appartient à une autre entreprise : vérifiez l\'adresse saisie.'], 403);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'usertype' => ['required', 'string', 'max:30'],
            'users' => ['sometimes', 'array', 'max:1'],
            'users.*' => ['array'],
            'clients' => ['sometimes', 'array', 'max:1'],
            'clients.*' => ['array'],
            'livreurs' => ['sometimes', 'array', 'max:1'],
            'livreurs.*' => ['array'],
        ]);

        if ($company->legacy_switch_at === null || $company->legacy_switch_at->isFuture()) {
            return response()->json(['status' => 'scheduled', 'switch_at' => $company->legacy_switch_at?->toIso8601String()]);
        }

        $courier = str_contains(mb_strtolower($data['usertype']), 'livreur');
        $user = $this->account($company, $data['phone'], $courier);
        // Compte créé dans l'ancienne application après la reprise : repris maintenant, avec sa fiche
        if ($user === null && ! empty($data['users'])) {
            $importer->importRows(
                $company,
                LegacyImportController::rows($data['users']),
                $courier ? [] : LegacyImportController::rows($data['clients'] ?? []),
                $courier ? LegacyImportController::rows($data['livreurs'] ?? []) : [],
            );
            $user = $this->account($company, $data['phone'], $courier);
        }
        if ($user === null) {
            return response()->json(['status' => 'unknown', 'message' => 'Ce compte n\'existe pas sur Jibiat.'], 404);
        }
        if (! $user->isActive()) {
            return response()->json(['status' => 'inactive', 'message' => 'Ce compte est suspendu sur Jibiat.'], 403);
        }

        $ticket = Str::random(48);
        Cache::put(self::cacheKey($ticket), $user->id, self::TICKET_SECONDS);

        return response()->json(['status' => 'switch', 'url' => $company->url('/bascule/'.$ticket)]);
    }

    /**
     * Lien ouvert par le navigateur : la session s'ouvre sur l'espace du compte. Le jeton passe dans le fragment
     * (#session=…) : jamais envoyé au serveur ni journalisé. Un ticket déjà utilisé ou expiré mène à la connexion.
     */
    public function open(string $ticket): RedirectResponse
    {
        $userId = Cache::pull(self::cacheKey($ticket));
        $user = $userId ? User::query()->with('company')->find($userId) : null;
        $here = Tenancy::enabled() ? app(Tenancy::class)->company() : null;

        if ($user === null || ! $user->isActive() || ($here !== null && $here->id !== $user->company_id)) {
            return redirect('/');
        }

        $session = AuthController::session($user, 'bascule');
        if ($user->legacy_switched_at === null) {
            $user->forceFill(['legacy_switched_at' => now()])->save();
        }

        return redirect(($user->isCourier() ? '/livreur' : '/marchand').'#session='.urlencode($session['token']));
    }

    private function account(Company $company, string $phone, bool $courier): ?User
    {
        $phone = PhoneNumber::normalize($phone);
        $query = User::query()->where('company_id', $company->id)->where('phone', $phone);

        return $courier
            ? $query->role(Role::Courier->value)->first()
            : $query->whereNotNull('merchant_id')->first();
    }

    private static function cacheKey(string $ticket): string
    {
        return 'legacy-switch:'.hash('sha256', $ticket);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Company $company): array
    {
        $switched = fn (bool $courier) => User::query()->where('company_id', $company->id)->whereNotNull('legacy_switched_at')
            ->when($courier, fn ($q) => $q->role(Role::Courier->value), fn ($q) => $q->whereNotNull('merchant_id'))->count();

        return [
            'switch_at' => $company->legacy_switch_at?->toIso8601String(),
            'started' => $company->legacy_switch_at !== null && ! $company->legacy_switch_at->isFuture(),
            'key_active' => $company->legacy_switch_key_hash !== null,
            'switched' => ['merchants' => $switched(false), 'couriers' => $switched(true)],
        ];
    }
}
