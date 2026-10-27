<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandedAppBuild;
use App\Services\Platform\BrandedApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Appels du workflow GitHub « branded-app.yml » pendant la fabrication d'une version : fiche, état, fichiers.
 * Protégés par le jeton de la version (en-tête X-Build-Token), valable jusqu'à la fin de la fabrication.
 */
class BrandedAppBuildController extends Controller
{
    public function __construct(private readonly BrandedApps $apps) {}

    public function manifest(Request $request, BrandedAppBuild $build): JsonResponse
    {
        $this->guard($request, $build);

        return response()->json(['data' => $this->apps->manifest($build)]);
    }

    public function status(Request $request, BrandedAppBuild $build): JsonResponse
    {
        $this->guard($request, $build);
        $data = $request->validate([
            'status' => ['required', Rule::in(['building', 'failed'])],
            'error' => ['nullable', 'string', 'max:2000'],
            'run_url' => ['nullable', 'url', 'max:255'],
        ]);
        $data['status'] === 'failed'
            ? $this->apps->fail($build, $data['error'] ?: 'La fabrication a échoué : voir l\'exécution sur GitHub.')
            : $this->apps->markBuilding($build, $data['run_url'] ?? null);
        if (filled($data['run_url'] ?? null)) {
            $build->forceFill(['run_url' => $data['run_url']])->save();
        }

        return response()->json(['data' => ['status' => $build->status]]);
    }

    public function upload(Request $request, BrandedAppBuild $build): JsonResponse
    {
        $this->guard($request, $build);
        $data = $request->validate([
            'app' => ['required', Rule::in(BrandedAppBuild::APPS)],
            'kind' => ['required', Rule::in(['aab', 'apk'])],
            // Application Android : 200 Mo au plus
            'file' => ['required', 'file', 'max:204800'],
        ]);
        $build = $this->apps->store($build, $data['app'], $data['kind'], $request->file('file'));

        return response()->json(['data' => ['status' => $build->status]]);
    }

    private function guard(Request $request, BrandedAppBuild $build): void
    {
        abort_unless($this->apps->authorize($build, $request->header('X-Build-Token')), 403, 'Jeton de fabrication absent, faux ou expiré.');
    }
}
