<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LoginRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Accounts\ConsoleTwoFactor;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Compte d'un autre espace que la page de connexion utilisée */
    public const WRONG_SPACE = [
        'merchant' => 'Ce compte est un compte e-commerçant : connectez-vous depuis l\'espace e-commerçant.',
        'courier' => 'Ce compte est un compte livreur : connectez-vous depuis l\'espace livreur.',
        'staff' => 'Ce compte est un compte de l\'équipe : connectez-vous depuis l\'espace équipe.',
    ];

    public function login(LoginRequest $request, ConsoleTwoFactor $twoFactor): JsonResponse
    {
        $login = trim($request->string('login'));

        // Plateforme : seulement les comptes de l'entreprise de l'adresse. Un même numéro peut
        // avoir un compte dans plusieurs entreprises : on garde celui dont le mot de passe correspond.
        $matches = (str_contains($login, '@')
            ? User::loginableHere()->where('email', mb_strtolower($login))
            : User::loginableHere()->where('phone', PhoneNumber::normalize($login)))
            ->with('company')
            ->get()
            ->filter(fn (User $u) => Hash::check($request->string('password'), $u->password));

        // Chaque page de connexion n'ouvre que les comptes de son espace (e-commerçant, livreur, équipe)
        $space = $request->input('space');
        if ($space !== null && $matches->isNotEmpty()) {
            $here = $matches->filter(fn (User $u) => $u->space() === $space);
            if ($here->isEmpty()) {
                $other = $matches->first()->space();

                return response()->json([
                    'message' => self::WRONG_SPACE[$other],
                    'space' => $other,
                ], 422);
            }
            $matches = $here;
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'login' => 'Ce numéro a un compte dans plusieurs entreprises : connectez-vous depuis l\'adresse de votre entreprise.',
            ]);
        }

        // Rechargé seul : ses relations (permissions, entreprise) se chargent normalement
        $user = $matches->first()?->fresh();
        if (! $user) {
            throw ValidationException::withMessages([
                'login' => 'Identifiant ou mot de passe incorrect.',
            ]);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'Compte suspendu. Contactez votre administrateur.'], 403);
        }

        $device = $request->input('device_name') ?: 'api';

        // Console : code envoyé par e-mail avant d'ouvrir la session (sauf appareil retenu)
        if ($twoFactor->applies($user) && ! $twoFactor->trusted($user, $request->input('trusted_device'))) {
            $challenge = $twoFactor->start($user, $device, (string) $request->ip());
            if ($challenge !== null) {
                return response()->json(['two_factor' => $challenge]);
            }
        }

        return response()->json(self::session($user, $device));
    }

    /**
     * Code de connexion de la console : la session s'ouvre ; « remember » retient l'appareil 30 jours.
     */
    public function twoFactor(Request $request, ConsoleTwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:48'],
            'code' => ['required', 'string', 'max:12'],
            'remember' => ['sometimes', 'boolean'],
        ], [], ['code' => 'code']);

        $result = $twoFactor->verify($data['challenge'], $data['code'], (bool) ($data['remember'] ?? false), $request->ip());

        return response()->json([...self::session($result['user'], $result['device']), 'trusted_device' => $result['trusted_device']]);
    }

    /**
     * Ouvre une session (jeton d'accès) : connexion, fin d'inscription, nouveau mot de passe.
     *
     * @return array<string, mixed>
     */
    public static function session(User $user, string $device = 'api'): array
    {
        $user->forceFill(['last_login_at' => now()])->save();

        // La réponse décrit l'espace de ce compte (droits, état de l'abonnement) : il est
        // l'utilisateur de la requête dès maintenant
        request()->setUserResolver(fn () => $user);

        return [
            // Super administrateur : session de 12 heures (la console donne accès à toute la plateforme)
            'token' => $user->createToken($device, ['*'], $user->isSuperAdmin() ? now()->addHours(12) : null)->plainTextToken,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user->load(['company.plan', 'merchant', 'courier']))->withPermissions(),
        ];
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user()->load(['company.plan', 'merchant', 'courier']))->withPermissions();
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Ne révoque que l'appareil courant
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Déconnecté.']);
    }
}
