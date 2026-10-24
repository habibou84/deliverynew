<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Legacy\LegacyAccountsImporter;
use App\Services\Legacy\LegacyZonesImporter;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Reprise depuis l'ancienne application (Laravel 5.8) par un bouton : l'administrateur crée une clé
 * (Paramètres), la colle dans la page « Migration vers Jibiat » de l'ancienne application, qui envoie
 * par paquets : les noms des zones (table « fees »), puis les e-commerçants, puis les livreurs. Les comptes de
 * l'équipe se recréent.
 */
class LegacyImportController extends Controller
{
    private const KEY_DAYS = 7;

    /** Lignes par table et par envoi (l'ancienne application envoie par paquets de 200) */
    private const MAX_ROWS = 500;

    private const STEPS = ['zones' => 'zones', 'clients' => 'e-commerçants', 'livreurs' => 'livreurs'];

    /** Module à copier dans l'ancienne application */
    public const MODULE = 'legacy/laravel-5.8/JibiatMigrationController.php';

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->describe($request->user()->company)]);
    }

    /** Nouvelle clé (affichée une seule fois) ; l'ancienne ne fonctionne plus */
    public function createKey(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $key = 'jbm_'.Str::random(40);
        $company->forceFill([
            'legacy_import_key_hash' => hash('sha256', $key),
            'legacy_import_key_expires_at' => now()->addDays(self::KEY_DAYS),
        ])->save();

        return response()->json(['data' => [...$this->describe($company), 'key' => $key]], 201);
    }

    public function destroyKey(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $company->forceFill(['legacy_import_key_hash' => null, 'legacy_import_key_expires_at' => null])->save();

        return response()->json(['data' => $this->describe($company)]);
    }

    public function module(): BinaryFileResponse
    {
        return response()->download(base_path(self::MODULE), 'JibiatMigrationController.php', ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Paquet envoyé par l'ancienne application. « step » : zones (lignes « fees », seulement leur nom), clients ou
     * livreurs, avec les comptes « users » qui leur sont liés.
     */
    public function receive(Request $request, LegacyAccountsImporter $importer, LegacyZonesImporter $zones): JsonResponse
    {
        $company = $this->companyForKey((string) $request->bearerToken());
        if ($company === null) {
            return response()->json(['message' => 'Clé de reprise invalide ou expirée : créez-en une dans Paramètres › Reprise de l\'ancienne application.'], 401);
        }
        // La clé suffit à désigner l'entreprise, même envoyée à l'adresse de la plateforme
        if (! $company->isActive()) {
            return response()->json(['message' => 'Le service de cette entreprise est suspendu.'], 403);
        }

        // Sur la plateforme, la clé ne vaut que pour l'adresse de son entreprise
        $here = Tenancy::enabled() ? app(Tenancy::class)->company() : null;
        if ($here !== null && $here->id !== $company->id) {
            return response()->json(['message' => 'Cette clé appartient à une autre entreprise : vérifiez l\'adresse saisie.'], 403);
        }

        $data = $request->validate([
            'step' => ['required', 'in:zones,clients,livreurs'],
            'dry_run' => ['sometimes', 'boolean'],
            'users' => ['present', 'array', 'max:'.self::MAX_ROWS * 3],
            'users.*' => ['array'],
            'clients' => ['sometimes', 'array', 'max:'.self::MAX_ROWS],
            'clients.*' => ['array'],
            'livreurs' => ['sometimes', 'array', 'max:'.self::MAX_ROWS],
            'livreurs.*' => ['array'],
            'fees' => ['sometimes', 'array', 'max:'.self::MAX_ROWS],
            'fees.*' => ['array'],
        ]);

        $step = $data['step'];
        $dryRun = (bool) ($data['dry_run'] ?? false);
        try {
            $report = $step === 'zones'
                ? $zones->importRows($company, self::rows($data['fees'] ?? []), $dryRun)
                : $importer->importRows(
                    $company,
                    self::rows($data['users']),
                    $step === 'clients' ? self::rows($data['clients'] ?? []) : [],
                    $step === 'livreurs' ? self::rows($data['livreurs'] ?? []) : [],
                    $dryRun,
                );
        } catch (Throwable $e) {
            // Au lieu de « Server Error » : la raison, lisible dans la page de l'ancienne application
            report($e);

            return response()->json(['message' => 'Erreur pendant l\'import ('.self::STEPS[$step].') : '
                .Str::limit(trim(strtok($e->getMessage(), "\n")), 200).' Envoyez ce message au support.'], 422);
        }

        if (! $dryRun) {
            $company->forceFill(['legacy_import_used_at' => now()])->save();
        }

        return response()->json(['data' => $report]);
    }

    private function companyForKey(string $key): ?Company
    {
        if (! str_starts_with($key, 'jbm_')) {
            return null;
        }

        return Company::query()
            ->where('legacy_import_key_hash', hash('sha256', $key))
            ->where('legacy_import_key_expires_at', '>', now())
            ->first();
    }

    /**
     * Valeurs telles que dans l'ancienne base : texte, ou null (comme la lecture d'un export SQL).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, string|null>>
     */
    public static function rows(array $rows): array
    {
        return array_values(array_map(fn (array $row) => array_map(
            fn ($value) => $value === null || is_array($value) ? null : (is_bool($value) ? (string) (int) $value : (string) $value),
            $row,
        ), $rows));
    }

    /**
     * @return array{active: bool, expires_at: string|null, used_at: string|null}
     */
    private function describe(Company $company): array
    {
        $expires = $company->legacy_import_key_expires_at;

        return [
            'active' => $company->legacy_import_key_hash !== null && $expires?->isFuture(),
            'expires_at' => $expires?->toIso8601String(),
            'used_at' => $company->legacy_import_used_at?->toIso8601String(),
        ];
    }
}
