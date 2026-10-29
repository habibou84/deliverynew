<?php

namespace App\Http\Controllers;

use App\Services\Platform\CourierApk;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Téléchargement de l'application Android des livreurs : page d'explication, fichier APK, version en ligne (pour
 * proposer la mise à jour dans l'application) et dépôt d'une nouvelle version par le workflow GitHub.
 */
class CourierAppController extends Controller
{
    public function __construct(private readonly CourierApk $apk) {}

    public function page(): View
    {
        return view('platform.courier-app', ['app' => $this->apk->latest()]);
    }

    public function download(): BinaryFileResponse
    {
        $app = $this->apk->latest();
        abort_if($app === null, 404, 'Application pas encore disponible.');

        return response()->download($this->apk->path(), "jibiat-livreur-{$app['version_name']}.apk", [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    public function latest(): JsonResponse
    {
        $app = $this->apk->latest();

        return response()->json(['data' => $app ? [...$app, 'page_url' => url('/app/livreur'), 'download_url' => url('/app/livreur.apk')] : null]);
    }

    public function upload(Request $request): JsonResponse
    {
        $token = (string) config('services.app_upload.token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Upload-Token')), 403, 'Jeton refusé.');

        $data = $request->validate([
            'file' => ['required', 'file', 'max:153600'],
            'version_code' => ['required', 'integer', 'min:1'],
            'version_name' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Za-z.\-]+$/'],
        ]);

        try {
            $app = $this->apk->publish($data['file'], (int) $data['version_code'], $data['version_name']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $app], 201);
    }
}
