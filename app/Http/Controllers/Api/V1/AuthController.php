<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LoginRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $login = trim($request->string('login'));

        $user = str_contains($login, '@')
            ? User::where('email', mb_strtolower($login))->first()
            : User::where('phone', PhoneNumber::normalize($login))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => 'Identifiant ou mot de passe incorrect.',
            ]);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'Compte suspendu. Contactez votre administrateur.'], 403);
        }

        return response()->json(self::session($user, $request->input('device_name') ?: 'api'));
    }

    /**
     * Ouvre une session (jeton d'accès) : connexion, fin d'inscription, nouveau mot de passe.
     *
     * @return array<string, mixed>
     */
    public static function session(User $user, string $device = 'api'): array
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return [
            'token' => $user->createToken($device)->plainTextToken,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user->load(['company', 'merchant', 'courier']))->withPermissions(),
        ];
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user()->load(['company', 'merchant', 'courier']))->withPermissions();
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
