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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bascule depuis l'ancienne application : à la date choisie par l'administrateur, un e-commerçant ou un livreur qui
 * ouvre l'ancienne application (connecté) arrive sur Jibiat déjà connecté, sur son espace.
 *
 * L'ancienne application (module legacy/laravel-5.8/JibiatSwitch.php) demande au serveur, avec la clé de bascule, un
 * lien de connexion pour le compte ouvert ; avant la date, elle reçoit la date et laisse faire. Le lien porte un
 * ticket à usage unique valable deux minutes, échangé ici contre une session. Un compte absent de Jibiat (créé dans
 * l'ancienne application après la reprise) est repris à ce moment-là, avec sa fiche.
 *
 * Avant la date (module version 2) : bandeau dans l'ancienne application, réglé ici par public. L'équipe y trouve
 * « Ouvrir Jibiat » ; les e-commerçants et les livreurs « Ouvrir la nouvelle application », qui les connecte déjà
 * sans les sortir de l'ancienne (mode « open »). Le module lit ce réglage avec le mode « config ».
 */
class LegacySwitchController extends Controller
{
    /** Module à copier dans l'ancienne application */
    public const MODULE = 'legacy/laravel-5.8/JibiatSwitch.php';

    private const TICKET_SECONDS = 120;

    /** La bascule ouvre des sessions pendant 60 jours après sa date, puis la clé ne sert plus à rien */
    public const OPEN_DAYS = 60;

    /** Version du module distribué (legacy/laravel-5.8/JibiatSwitch.php) */
    public const MODULE_VERSION = 2;

    /** Bandeau de l'ancienne application : coupé par défaut */
    public const PREVIEW = ['team' => false, 'merchants' => false, 'couriers' => false, 'message' => null];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->describe($request->user()->company)]);
    }

    /** Date de la bascule (passée ou présente : tout de suite) ; null l'annule */
    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate(['switch_at' => ['present', 'nullable', 'date']], [], ['switch_at' => 'date de bascule']);
        $company = $request->user()->company;
        // Bascule commencée : elle ne se repousse plus (seule l'annulation reste possible, pour revenir en arrière)
        if (isset($data['switch_at']) && $company->legacy_switch_at !== null && Carbon::parse($company->legacy_switch_at)->lte(now())) {
            throw ValidationException::withMessages(['switch_at' => 'La bascule a déjà commencé : sa date ne peut plus changer (vous pouvez seulement l\'annuler).']);
        }
        // Date passée : la bascule commence tout de suite (jamais antidatée)
        $at = isset($data['switch_at']) ? Carbon::parse($data['switch_at'])->max(now()) : null;
        $company->forceFill(['legacy_switch_at' => $at])->save();

        return response()->json(['data' => $this->describe($company)]);
    }

    /** Bandeau dans l'ancienne application, par public, et message affiché aux e-commerçants et aux livreurs */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team' => ['required', 'boolean'],
            'merchants' => ['required', 'boolean'],
            'couriers' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:200'],
        ], [], ['message' => 'message']);
        $company = $request->user()->company;
        $company->forceFill(['legacy_switch_preview' => [
            'team' => (bool) $data['team'],
            'merchants' => (bool) $data['merchants'],
            'couriers' => (bool) $data['couriers'],
            'message' => filled($data['message'] ?? null) ? trim($data['message']) : null,
        ]])->save();

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
        if (! $company->isActive()) {
            return response()->json(['message' => 'Le service de cette entreprise est suspendu.'], 403);
        }
        $here = Tenancy::enabled() ? app(Tenancy::class)->company() : null;
        if ($here !== null && $here->id !== $company->id) {
            return response()->json(['message' => 'Cette clé appartient à une autre entreprise : vérifiez l\'adresse saisie.'], 403);
        }

        $data = $request->validate([
            // visit (par défaut) : page ouverte ; open : bouton du bandeau ; config : réglages du bandeau
            'mode' => ['nullable', 'in:visit,open,config'],
            'module_version' => ['nullable', 'integer'],
            'phone' => ['required_unless:mode,config', 'nullable', 'string', 'max:30'],
            'usertype' => ['required_unless:mode,config', 'nullable', 'string', 'max:30'],
            'users' => ['sometimes', 'array', 'max:1'],
            'users.*' => ['array'],
            'clients' => ['sometimes', 'array', 'max:1'],
            'clients.*' => ['array'],
            'livreurs' => ['sometimes', 'array', 'max:1'],
            'livreurs.*' => ['array'],
        ]);

        $mode = $data['mode'] ?? 'visit';
        Cache::put(self::seenKey($company), ['version' => (int) ($data['module_version'] ?? 1), 'at' => now()->toIso8601String()], now()->addDays(30));
        if ($mode === 'config') {
            return response()->json(['status' => 'config', ...$this->bannerConfig($company)]);
        }

        $courier = str_contains(mb_strtolower((string) $data['usertype']), 'livreur');
        $started = $company->legacy_switch_at !== null && ! $company->legacy_switch_at->isFuture();
        // Avant la date : seul le bouton du bandeau ouvre une session, et seulement si le bandeau est montré à ce public
        // Sécurité : sans date de bascule fixée, le bandeau n'ouvre rien (sinon la clé ouvrait des sessions sans fin)
        $preview = $mode === 'open' && $company->legacy_switch_at !== null
            && ($this->previewSettings($company)[$courier ? 'couriers' : 'merchants'] ?? false);
        if (! $started && ! $preview) {
            return response()->json(['status' => 'scheduled', 'switch_at' => $company->legacy_switch_at?->toIso8601String()]);
        }
        if ($started && $company->legacy_switch_at->copy()->addDays(self::OPEN_DAYS)->isPast()) {
            return response()->json(['status' => 'closed', 'message' => 'La période de bascule est terminée : les comptes se connectent directement sur Jibiat.'], 410);
        }

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

    /** @return array{team: bool, merchants: bool, couriers: bool, message: ?string} */
    private function previewSettings(Company $company): array
    {
        return [...self::PREVIEW, ...($company->legacy_switch_preview ?? [])];
    }

    /**
     * Ce que le module affiche dans l'ancienne application : à qui, quoi, et l'adresse de l'espace Jibiat.
     * Après la date, les e-commerçants et livreurs sont envoyés sur Jibiat : seul le bandeau de l'équipe reste.
     *
     * @return array<string, mixed>
     */
    private function bannerConfig(Company $company): array
    {
        $preview = $this->previewSettings($company);
        $started = $company->legacy_switch_at !== null && ! $company->legacy_switch_at->isFuture();

        return [
            'switch_at' => $company->legacy_switch_at?->toIso8601String(),
            'started' => $started,
            'banner' => [
                'team' => $preview['team'],
                'merchants' => $preview['merchants'] && ! $started,
                'couriers' => $preview['couriers'] && ! $started,
                'message' => $preview['message'],
            ],
            'name' => $company->name,
            'url' => $company->url('/admin'),
        ];
    }

    private static function seenKey(Company $company): string
    {
        return 'legacy-switch-module:'.$company->id;
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
            'ends_at' => $company->legacy_switch_at?->copy()->addDays(self::OPEN_DAYS)->toIso8601String(),
            'key_active' => $company->legacy_switch_key_hash !== null,
            'switched' => ['merchants' => $switched(false), 'couriers' => $switched(true)],
            'preview' => $this->previewSettings($company),
            // Dernier appel du module installé : sa version dit s'il sait afficher le bandeau
            'module' => [...(Cache::get(self::seenKey($company)) ?? ['version' => null, 'at' => null]), 'latest' => self::MODULE_VERSION],
        ];
    }
}
