<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\Accounts\PhoneVerifier;
use App\Support\Branding;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * « Me connecter par WhatsApp » : la personne donne son numéro, envoie le message WhatsApp
 * prérempli (ou le code SMS en dernier recours), et la page ouvre sa session, sans mot de passe.
 * Même règles que la connexion classique : espace de la page, compte actif, une entreprise.
 */
class WhatsAppLoginController extends Controller
{
    public function __construct(private readonly PhoneVerifier $verifier) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new PhoneNumber],
            'space' => ['nullable', 'in:merchant,courier,staff'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [], ['phone' => 'téléphone']);
        $phone = Phone::normalize($data['phone']);

        $matches = User::loginableHere()->where('phone', $phone)->with('company')->get();

        // Même réponse que le numéro ait un compte ou non (ni son type, ni s'il est suspendu) : seul le titulaire du
        // numéro l'apprend, sur WhatsApp, après avoir envoyé le message
        $space = $data['space'] ?? null;
        $here = $space !== null ? $matches->filter(fn (User $u) => $u->space() === $space) : $matches;
        $wrongSpace = $here->isEmpty() && $matches->isNotEmpty() ? $matches->first()->space() : null;

        // Un même numéro dans plusieurs entreprises : celle de la page d'accueil, sinon on demande l'adresse de l'entreprise
        $company = Branding::company();
        $user = $here->count() > 1 ? $here->firstWhere('company_id', $company?->id) : $here->first();
        if ($user === null && $here->count() > 1) {
            throw ValidationException::withMessages([
                'phone' => 'Ce numéro a un compte dans plusieurs entreprises : connectez-vous depuis l\'adresse de votre entreprise.',
            ]);
        }
        $user = $user?->fresh();
        // Comptes de la plateforme (console) : connexion par mot de passe seulement
        if ($user !== null && $user->company === null) {
            $user = null;
        }

        $company = $user?->company ?? $company ?? $matches->first()?->company;
        abort_if($company === null, 404, 'Connectez-vous depuis l\'adresse de votre entreprise.');

        [$verification, $token] = $this->verifier->start($company, PhoneVerification::LOGIN, $phone, $user, [
            'device' => $data['device_name'] ?? 'whatsapp',
            ...($wrongSpace !== null ? ['wrong_space' => $wrongSpace] : []),
        ], $request->ip());

        return response()->json(['token' => $token, 'data' => VerificationController::describe($this->verifier, $verification)], 201);
    }
}
