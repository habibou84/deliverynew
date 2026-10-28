<?php

namespace App\Services\Platform;

use App\Models\BrandedApp;
use App\Models\BrandedAppBuild;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Support\Appearance;
use App\Support\Branding;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Applications Android E-commerçant et Livreur au nom d'une entreprise (console › Applications à leur nom) :
 * réglages, clé de signature gardée par Jibiat, fabrication par le workflow GitHub « branded-app.yml » qui lit la
 * fiche de la version et renvoie ses fichiers avec un jeton propre à la version.
 */
class BrandedApps
{
    /** Validité du jeton d'une version (le workflow dure une vingtaine de minutes) */
    public const TOKEN_HOURS = 3;

    /** Application fabriquée (mobile/<application>) → espace de l'apparence */
    public const SPACES = ['ecommercant' => 'marchand', 'livreur' => 'livreur'];

    public const DISK = 'local';

    /**
     * Réglages proposés pour une nouvelle entreprise : identifiants à partir de son adresse, noms de l'apparence.
     *
     * @return array<string, string>
     */
    public function defaults(Company $company): array
    {
        $base = preg_replace('/[^a-z0-9]/', '', Str::ascii(mb_strtolower((string) $company->slug))) ?: 'entreprise'.$company->id;
        if (! preg_match('/^[a-z]/', $base)) {
            $base = 'app'.$base;
        }
        $appearance = Appearance::for($company)['spaces'];

        return [
            'merchant_app_id' => "ci.{$base}.ecommercant",
            'courier_app_id' => "ci.{$base}.livreur",
            'merchant_app_name' => mb_substr($appearance['marchand']['app_name'], 0, 30),
            'courier_app_name' => mb_substr($appearance['livreur']['app_name'], 0, 30),
        ];
    }

    public function create(Company $company): BrandedApp
    {
        $app = BrandedApp::query()->firstOrNew(['company_id' => $company->id]);
        if (! $app->exists) {
            $app->fill($this->defaults($company) + ['enabled' => true]);
        }
        $app->enabled = true;
        $app->save();
        $this->ensureKey($app);

        return $app;
    }

    /**
     * Clé de signature de l'entreprise (« clé d'importation » de Google Play) : RSA 2048, certificat de 25 ans,
     * fichier PKCS12 protégé par un mot de passe au hasard. Créée une seule fois : la perdre empêcherait de mettre à
     * jour les applications publiées.
     */
    public function ensureKey(BrandedApp $app): void
    {
        if (filled($app->keystore)) {
            return;
        }
        $company = $app->company;
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Clé de signature impossible à créer (OpenSSL).');
        }
        $name = Str::limit(Str::ascii((string) $company->name), 60, '') ?: 'Entreprise';
        $csr = openssl_csr_new(['commonName' => $name, 'organizationName' => $name, 'stateOrProvinceName' => 'Abidjan', 'countryName' => 'CI'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 25 * 365, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        $password = Str::random(32);
        $alias = $app->key_alias ?: 'upload';
        if ($certificate === false || ! openssl_pkcs12_export($certificate, $p12, $key, $password, ['friendly_name' => $alias])) {
            throw new RuntimeException('Clé de signature impossible à créer (OpenSSL).');
        }

        $app->forceFill([
            'keystore' => base64_encode($p12),
            'keystore_password' => $password,
            'key_alias' => $alias,
            'keystore_created_at' => now(),
        ])->save();
    }

    /**
     * Prochaine version : créée en attente, puis le workflow GitHub est lancé. Sans jeton GitHub ou en cas de refus,
     * la version passe en échec avec l'explication.
     */
    public function build(BrandedApp $app, ?User $by = null): BrandedAppBuild
    {
        $this->ensureKey($app);
        $token = Str::random(48);

        $build = DB::transaction(function () use ($app, $by, $token) {
            $locked = BrandedApp::query()->whereKey($app->id)->lockForUpdate()->first();
            $code = $locked->next_version_code;
            $locked->forceFill(['next_version_code' => $code + 1])->save();

            return $locked->builds()->create([
                'version_name' => config('services.github_apps.version', '1.0').'.'.$code,
                'version_code' => $code,
                'status' => 'queued',
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addHours(self::TOKEN_HOURS),
                'created_by' => $by?->id,
            ]);
        });

        $this->dispatch($build, $token);

        return $build->refresh();
    }

    private function dispatch(BrandedAppBuild $build, string $token): void
    {
        $config = config('services.github_apps');
        $githubToken = app(GithubAppsToken::class)->token();
        if ($githubToken === null) {
            $this->fail($build, 'Jeton GitHub absent : enregistrez-le dans la console (Applications à leur nom › Jeton GitHub) ou dans le fichier .env du serveur (GITHUB_APPS_TOKEN). La fabrication ne peut pas être lancée.');

            return;
        }

        try {
            $response = Http::withToken($githubToken)->acceptJson()->timeout(15)
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->post("https://api.github.com/repos/{$config['repo']}/actions/workflows/{$config['workflow']}/dispatches", [
                    'ref' => $config['ref'],
                    'inputs' => [
                        'build_id' => (string) $build->id,
                        'token' => $token,
                        'platform_url' => Tenancy::platformUrl(),
                    ],
                ]);
        } catch (Throwable $e) {
            $this->fail($build, 'GitHub injoignable : '.Str::limit($e->getMessage(), 200));

            return;
        }

        if (! $response->successful()) {
            $this->fail($build, match ($response->status()) {
                401 => 'Jeton GitHub refusé (expiré ou faux) : remplacez-le dans la console (Applications à leur nom › Jeton GitHub).',
                403, 404 => 'Ce jeton GitHub ne peut pas lancer le workflow branded-app.yml de '.$config['repo'].' (droit Actions en écriture, branche '.$config['ref'].').',
                default => 'GitHub a refusé le lancement (HTTP '.$response->status().').',
            });

            return;
        }

        $build->forceFill(['run_url' => "https://github.com/{$config['repo']}/actions/workflows/{$config['workflow']}"])->save();
    }

    public function fail(BrandedAppBuild $build, string $error): void
    {
        $build->forceFill([
            'status' => 'failed',
            'error' => Str::limit($error, 490),
            'token_hash' => null,
            'finished_at' => now(),
        ])->save();
    }

    /** Jeton de la version valable (non expiré, version pas terminée) */
    public function authorize(BrandedAppBuild $build, ?string $token): bool
    {
        return filled($token) && $build->token_hash !== null && $build->open()
            && $build->token_expires_at?->isFuture() && hash_equals($build->token_hash, hash('sha256', (string) $token));
    }

    /**
     * Fiche lue par le workflow : entreprise intégrée à l'application, nom, identifiant, couleur et icône de chaque
     * application, version, clé de signature.
     *
     * @return array<string, mixed>
     */
    public function manifest(BrandedAppBuild $build): array
    {
        $app = $build->app;
        $company = $app->company;
        $logo = Branding::logoUrl($company);
        $apps = [];
        foreach (self::SPACES as $name => $space) {
            $apps[$name] = [
                'app_id' => $name === 'ecommercant' ? $app->merchant_app_id : $app->courier_app_id,
                'name' => $this->appName($app, $name),
                'color' => Appearance::spaceColor($company, $space),
                'icon_url' => $company->url(Appearance::iconUrl($company, $space, 'maskable-512')),
            ];
        }

        return [
            'build' => ['id' => $build->id, 'version_name' => $build->version_name, 'version_code' => $build->version_code],
            'company' => [
                'code' => $company->slug,
                'name' => $company->name,
                'url' => $company->url(),
                'logo_url' => $logo ? $company->url($logo) : null,
            ],
            'apps' => $apps,
            'signing' => [
                'keystore' => $app->keystore,
                'password' => $app->keystore_password,
                'alias' => $app->key_alias,
            ],
        ];
    }

    public function appName(BrandedApp $app, string $name): string
    {
        $saved = $name === 'ecommercant' ? $app->merchant_app_name : $app->courier_app_name;

        return filled($saved) ? $saved : $this->defaults($app->company)[$name === 'ecommercant' ? 'merchant_app_name' : 'courier_app_name'];
    }

    public function markBuilding(BrandedAppBuild $build, ?string $runUrl): void
    {
        $build->forceFill([
            'status' => 'building',
            'started_at' => $build->started_at ?? now(),
            'run_url' => $runUrl ?: $build->run_url,
        ])->save();
    }

    /**
     * Fichier fabriqué (AAB pour le Play Store, APK pour essayer) ; la version est prête quand les deux applications
     * ont leur AAB, et le jeton ne sert plus.
     */
    public function store(BrandedAppBuild $build, string $name, string $kind, UploadedFile $file): BrandedAppBuild
    {
        $path = $file->storeAs($this->folder($build), "{$name}.{$kind}", self::DISK);
        $files = $build->files ?? [];
        $files[$name][$kind] = $path;
        $build->files = $files;
        if ($build->status === 'queued') {
            $this->markBuilding($build, null);
        }
        if (collect(BrandedAppBuild::APPS)->every(fn ($a) => filled($files[$a]['aab'] ?? null))) {
            $build->forceFill(['status' => 'ready', 'error' => null, 'token_hash' => null, 'finished_at' => now()]);
        }
        $build->save();

        return $build;
    }

    public function folder(BrandedAppBuild $build): string
    {
        return "branded-apps/{$build->app->company_id}/{$build->version_code}";
    }

    /**
     * Ce qu'il faut avant la publication sur Google Play.
     *
     * @return list<array{key: string, label: string, ok: bool, hint: ?string}>
     */
    public function checklist(BrandedApp $app): array
    {
        $company = $app->company;

        return [
            ['key' => 'google', 'label' => 'Compte Google Play à son nom, Jibiat invité comme gestionnaire', 'ok' => $app->google_linked_at !== null,
                'hint' => $app->google_linked_at ? 'Relié le '.$app->google_linked_at->format('d/m/Y') : 'L\'entreprise ouvre son compte développeur et invite Jibiat'],
            ['key' => 'icons', 'label' => 'Icône de l\'entreprise (logo ou icône envoyée)', 'ok' => Appearance::hasCustomIcon($company, 'marchand'),
                'hint' => 'Paramètres › Apparence des espaces de l\'entreprise'],
            ['key' => 'privacy', 'label' => 'Page de confidentialité', 'ok' => true, 'hint' => $company->url('/confidentialite')],
            ['key' => 'deletion', 'label' => 'Suppression du compte depuis l\'application', 'ok' => true, 'hint' => $company->url('/suppression-du-compte')],
            ['key' => 'description', 'label' => 'Description de la fiche Play Store', 'ok' => filled($app->short_description) && filled($app->full_description),
                'hint' => 'Courte (80 caractères) et longue'],
            ['key' => 'key', 'label' => 'Clé de signature gardée par Jibiat', 'ok' => filled($app->keystore), 'hint' => $app->keystore_created_at?->format('d/m/Y')],
        ];
    }

    /** La formule de l'entreprise est la plus haute (l'option lui est réservée, sans blocage) */
    public function topPlan(Company $company): bool
    {
        $top = Plan::query()->where('is_active', true)->max('position');

        return $company->plan_id !== null && $top !== null && (int) $company->loadMissing('plan')->plan?->position >= (int) $top;
    }

    /**
     * État résumé pour la liste : à relier, en cours, à publier, échec, publiée, à fabriquer.
     *
     * @return array{0: string, 1: string}
     */
    public function state(BrandedApp $app): array
    {
        $last = $app->builds()->latest('id')->first();

        return match (true) {
            ! $app->enabled => ['off', 'Désactivée'],
            $app->google_linked_at === null => ['link', 'À relier'],
            $last === null => ['todo', 'À fabriquer'],
            $last->open() => ['building', 'En cours'],
            $last->status === 'failed' => ['failed', 'Échec'],
            $last->status === 'ready' => ['ready', 'À publier'],
            default => ['published', 'Publiée'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function presentBuild(BrandedAppBuild $build): array
    {
        $files = [];
        foreach (BrandedAppBuild::APPS as $name) {
            foreach (['aab', 'apk'] as $kind) {
                if (filled($build->files[$name][$kind] ?? null)) {
                    $files[] = ['app' => $name, 'kind' => $kind];
                }
            }
        }

        return [
            'id' => $build->id,
            'version_name' => $build->version_name,
            'version_code' => $build->version_code,
            'status' => $build->status,
            'status_label' => BrandedAppBuild::STATUSES[$build->status] ?? $build->status,
            'error' => $build->error,
            'run_url' => $build->run_url,
            'files' => $files,
            'by' => $build->author?->name,
            'created_at' => $build->created_at?->toIso8601String(),
            'finished_at' => $build->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(BrandedApp $app, bool $full = false): array
    {
        $company = $app->company;
        [$state, $label] = $this->state($app);
        $data = [
            'company' => ['id' => $company->id, 'name' => $company->name, 'slug' => $company->slug],
            'enabled' => $app->enabled,
            'state' => $state,
            'state_label' => $label,
            'top_plan' => $this->topPlan($company),
            'last_version' => $app->builds()->latest('id')->value('version_name'),
        ];
        if (! $full) {
            return $data;
        }
        $apps = [];
        foreach (self::SPACES as $name => $space) {
            $apps[$name] = [
                'name' => $this->appName($app, $name),
                'app_id' => $name === 'ecommercant' ? $app->merchant_app_id : $app->courier_app_id,
                'color' => Appearance::spaceColor($company, $space),
                'icon_url' => $company->url(Appearance::iconUrl($company, $space, '192')),
            ];
        }

        return $data + [
            'settings' => [
                'merchant_app_id' => $app->merchant_app_id,
                'courier_app_id' => $app->courier_app_id,
                'merchant_app_name' => $app->merchant_app_name,
                'courier_app_name' => $app->courier_app_name,
                'short_description' => $app->short_description,
                'full_description' => $app->full_description,
                'google_linked' => $app->google_linked_at !== null,
            ],
            'defaults' => $this->defaults($company),
            'apps' => $apps,
            'url' => $company->url(),
            'key_created_at' => $app->keystore_created_at?->toIso8601String(),
            'checklist' => $this->checklist($app),
            'builds' => $app->builds()->with('author:id,name')->latest('id')->limit(20)->get()->map(fn ($b) => $this->presentBuild($b))->values(),
            'github_ready' => app(GithubAppsToken::class)->token() !== null,
        ];
    }

    public function file(BrandedAppBuild $build, string $name, string $kind): ?string
    {
        $path = $build->files[$name][$kind] ?? null;

        return $path !== null && Storage::disk(self::DISK)->exists($path) ? $path : null;
    }
}
