<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\GmailConnection;
use App\Services\Platform\WaveSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Console : relier un compte Gmail pour envoyer les e-mails de la plateforme (connexion Google, sans mot de passe).
 */
class ConsoleGmailController extends Controller
{
    public function __construct(private readonly GmailConnection $gmail) {}

    /**
     * Application Google (client OAuth, le même que Jibiat Marchand possible) : identifiant et code secret. Le
     * secret n'est jamais renvoyé ; vide, il est gardé.
     */
    public function client(Request $request, WaveSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'client_secret' => [$this->gmail->available() ? 'nullable' : 'required', 'string', 'max:255'],
        ], [], ['client_id' => 'identifiant du client', 'client_secret' => 'code secret du client']);
        $this->gmail->saveClient($data['client_id'], $data['client_secret'] ?? null);

        return response()->json(['data' => $settings->present()]);
    }

    /** Lien vers la page d'autorisation de Google */
    public function connect(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => ['url' => $this->gmail->authorizationUrl($request->user())]]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['gmail' => $e->getMessage()]);
        }
    }

    public function disconnect(WaveSettings $settings): JsonResponse
    {
        $this->gmail->disconnect();

        return response()->json(['data' => $settings->present()]);
    }

    /**
     * Retour de Google (navigateur, sans jeton de session : le « state » désigne le super administrateur).
     */
    public function callback(Request $request): RedirectResponse
    {
        $back = '/console/paiements';
        if ($request->filled('error')) {
            return redirect($back.'?gmail=refus');
        }

        try {
            $this->gmail->complete((string) $request->query('code'), (string) $request->query('state'));
        } catch (RuntimeException $e) {
            return redirect($back.'?gmail=erreur&message='.urlencode($e->getMessage()));
        }

        return redirect($back.'?gmail=ok');
    }
}
