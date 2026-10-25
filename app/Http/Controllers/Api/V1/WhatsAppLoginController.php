<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
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
        if ($matches->isEmpty()) {
            throw new BusinessRuleException('Aucun compte avec ce numéro. Vérifiez-le, ou créez votre compte.', 'phone');
        }

        $space = $data['space'] ?? null;
        if ($space !== null) {
            $here = $matches->filter(fn (User $u) => $u->space() === $space);
            if ($here->isEmpty()) {
                $other = $matches->first()->space();

                return response()->json(['message' => AuthController::WRONG_SPACE[$other], 'space' => $other], 422);
            }
            $matches = $here;
        }

        // Un même numéro dans plusieurs entreprises : celle de la page d'accueil, sinon on demande l'adresse de l'entreprise
        $company = Branding::company();
        $user = $matches->count() > 1 ? $matches->firstWhere('company_id', $company?->id) : $matches->first();
        if ($user === null) {
            throw ValidationException::withMessages([
                'phone' => 'Ce numéro a un compte dans plusieurs entreprises : connectez-vous depuis l\'adresse de votre entreprise.',
            ]);
        }
        $user = $user->fresh();
        if (! $user->isActive()) {
            return response()->json(['message' => 'Compte suspendu. Contactez votre administrateur.'], 403);
        }

        // Comptes de la plateforme (console) : connexion par mot de passe seulement
        if ($user->company === null) {
            throw ValidationException::withMessages(['phone' => 'Ce compte se connecte avec son mot de passe.']);
        }

        [$verification, $token] = $this->verifier->start($user->company, PhoneVerification::LOGIN, $phone, $user, [
            'device' => $data['device_name'] ?? 'whatsapp',
        ], $request->ip());

        return response()->json(['token' => $token, 'data' => VerificationController::describe($this->verifier, $verification)], 201);
    }
}
