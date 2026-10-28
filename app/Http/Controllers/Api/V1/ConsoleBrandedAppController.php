<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BrandedApp;
use App\Models\BrandedAppBuild;
use App\Models\Company;
use App\Services\Platform\BrandedApps;
use App\Services\Platform\GithubAppsToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Console › Applications à leur nom : applications Android E-commerçant et Livreur au nom d'une entreprise
 * (réglages, liste avant la publication, versions fabriquées). Lecture pour l'équipe, actions au Propriétaire.
 */
class ConsoleBrandedAppController extends Controller
{
    /** Identifiant Android : segments en minuscules séparés par des points (ci.entreprise.ecommercant) */
    private const APP_ID = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$/';

    public function __construct(private readonly BrandedApps $apps, private readonly GithubAppsToken $github) {}

    public function index(): JsonResponse
    {
        $apps = BrandedApp::query()->with('company.plan')->get()->sortBy(fn (BrandedApp $a) => $a->company?->name)->values();

        return response()->json(['data' => [
            'apps' => $apps->map(fn (BrandedApp $a) => $this->apps->present($a))->values(),
            // Entreprises qu'on peut ajouter
            'companies' => Company::query()->whereNotIn('id', $apps->pluck('company_id'))->orderBy('name')->get(['id', 'name'])
                ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'github_ready' => $this->github->token() !== null,
            'github' => $this->github->state(),
        ]]);
    }

    /**
     * Jeton GitHub saisi par le Propriétaire : gardé chiffré, jamais renvoyé, vérifié aussitôt auprès de GitHub.
     * Celui du fichier .env reste prioritaire.
     */
    public function saveToken(Request $request): JsonResponse
    {
        abort_if($this->github->source() === 'env', 422, 'Le jeton est réglé dans le fichier .env du serveur : modifiez-le là-bas.');
        $data = $request->validate(['token' => ['required', 'string', 'regex:'.GithubAppsToken::PATTERN]], [
            'token.regex' => 'Ce n\'est pas un jeton GitHub (il commence par github_pat_ ou ghp_).',
        ], ['token' => 'jeton GitHub']);

        $this->github->save($data['token']);
        $error = $this->github->check();

        return response()->json(['data' => $this->github->state(), 'message' => $error ?? 'Jeton enregistré et vérifié auprès de GitHub.']);
    }

    public function checkToken(): JsonResponse
    {
        $error = $this->github->check();

        return response()->json(['data' => $this->github->state(), 'message' => $error ?? 'Le jeton GitHub marche.']);
    }

    public function removeToken(): JsonResponse
    {
        abort_if($this->github->source() === 'env', 422, 'Le jeton est réglé dans le fichier .env du serveur : retirez-le là-bas.');
        $this->github->remove();

        return response()->json(['data' => $this->github->state()]);
    }

    public function show(Company $company): JsonResponse
    {
        $app = BrandedApp::query()->where('company_id', $company->id)->firstOrFail();

        return response()->json(['data' => $this->apps->present($app, true)]);
    }

    public function store(Company $company): JsonResponse
    {
        $app = $this->apps->create($company);

        return response()->json(['data' => $this->apps->present($app->refresh(), true)], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $app = BrandedApp::query()->where('company_id', $company->id)->firstOrFail();
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'merchant_app_id' => ['sometimes', 'string', 'max:150', 'regex:'.self::APP_ID, 'different:courier_app_id',
                Rule::unique('branded_apps', 'merchant_app_id')->ignore($app->id), Rule::unique('branded_apps', 'courier_app_id')],
            'courier_app_id' => ['sometimes', 'string', 'max:150', 'regex:'.self::APP_ID,
                Rule::unique('branded_apps', 'courier_app_id')->ignore($app->id), Rule::unique('branded_apps', 'merchant_app_id')],
            'merchant_app_name' => ['sometimes', 'nullable', 'string', 'max:30'],
            'courier_app_name' => ['sometimes', 'nullable', 'string', 'max:30'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:80'],
            'full_description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'google_linked' => ['sometimes', 'boolean'],
        ], [
            'merchant_app_id.regex' => 'Identifiant en minuscules, en au moins trois parties séparées par des points (ex. ci.entreprise.ecommercant).',
            'courier_app_id.regex' => 'Identifiant en minuscules, en au moins trois parties séparées par des points (ex. ci.entreprise.livreur).',
        ], [
            'merchant_app_id' => 'identifiant de l\'application e-commerçant',
            'courier_app_id' => 'identifiant de l\'application livreur',
            'merchant_app_name' => 'nom de l\'application e-commerçant',
            'courier_app_name' => 'nom de l\'application livreur',
            'short_description' => 'description courte',
            'full_description' => 'description longue',
        ]);

        // Identifiants figés après la première version : Google Play n'accepte pas de changement
        if ($app->builds()->where('status', '!=', 'failed')->exists()) {
            unset($data['merchant_app_id'], $data['courier_app_id']);
        }
        if (array_key_exists('google_linked', $data)) {
            $app->google_linked_at = $data['google_linked'] ? ($app->google_linked_at ?? now()) : null;
            unset($data['google_linked']);
        }
        $app->fill($data)->save();

        return response()->json(['data' => $this->apps->present($app->refresh(), true)]);
    }

    public function build(Request $request, Company $company): JsonResponse
    {
        $app = BrandedApp::query()->where('company_id', $company->id)->firstOrFail();
        abort_unless($app->enabled, 422, 'Les applications de cette entreprise sont désactivées.');
        // Formule : applications incluses ou option active (sinon l'application publiée marche, sans nouvelle version)
        abort_unless($this->apps->access($company)['allowed'], 422, 'Applications à son nom non comprises dans la formule de l\'entreprise : incluez-les dans sa formule ou acceptez son option (Console › Formules).');
        abort_if($app->builds()->whereIn('status', ['queued', 'building'])->where('token_expires_at', '>', now())->exists(), 422, 'Une fabrication est déjà en cours.');

        $build = $this->apps->build($app, $request->user());

        return response()->json(['data' => $this->apps->present($app->refresh(), true), 'build' => $this->apps->presentBuild($build)], 201);
    }

    /** Version envoyée sur le Play Store (à la main, en attendant l'envoi automatique) */
    public function published(Company $company, BrandedAppBuild $build): JsonResponse
    {
        abort_unless($build->app->company_id === $company->id, 404);
        abort_unless($build->status === 'ready', 422, 'Seule une version prête peut être marquée publiée.');
        $build->forceFill(['status' => 'published'])->save();

        return response()->json(['data' => $this->apps->present($build->app, true)]);
    }

    public function download(Company $company, BrandedAppBuild $build, string $app, string $kind): StreamedResponse
    {
        abort_unless($build->app->company_id === $company->id && in_array($app, BrandedAppBuild::APPS, true) && in_array($kind, ['aab', 'apk'], true), 404);
        $path = $this->apps->file($build, $app, $kind);
        abort_if($path === null, 404, 'Fichier introuvable.');

        return Storage::disk(BrandedApps::DISK)->download($path, "{$company->slug}-{$app}-{$build->version_name}.{$kind}");
    }
}
