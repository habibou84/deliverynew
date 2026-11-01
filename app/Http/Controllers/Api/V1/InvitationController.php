<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Accounts\Invitations;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

/**
 * Invitations de l'équipe et des livreurs : création du lien par l'administrateur (envoyé par son WhatsApp),
 * lecture et acceptation du lien par la personne invitée (session ouverte, mot de passe facultatif).
 */
class InvitationController extends Controller
{
    public function __construct(private readonly Invitations $invitations) {}

    public function store(Request $request, User $user): JsonResponse
    {
        Gate::authorize('changeCredentials', $user);
        abort_if($user->merchant_id !== null, 422, 'Les e-commerçants se connectent par WhatsApp ou s\'inscrivent eux-mêmes.');

        return response()->json(['data' => $this->invitations->create($user, $request->user())], 201);
    }

    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->find($token);
        abort_if($invitation === null, 404, "Cette invitation n'est plus valable. Demandez-en une nouvelle à votre administrateur.");
        $user = $invitation->user;

        return response()->json(['data' => [
            'name' => $user->name,
            'phone' => PhoneNumber::display($user->phone),
            'role_label' => $user->primaryRole()?->label(),
            'company' => $user->company?->name,
            'has_password' => $user->password !== null,
            // Équipe de la console : mot de passe obligatoire, puis connexion avec le code envoyé par e-mail
            'console' => $user->isPlatformStaff(),
            'expires_at' => $invitation->expires_at,
        ]]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->invitations->find($token);
        abort_if($invitation === null, 404, "Cette invitation n'est plus valable. Demandez-en une nouvelle à votre administrateur.");

        $console = $invitation->user?->isPlatformStaff() ?? false;
        $data = $request->validate([
            'password' => [$console ? 'required' : 'nullable', 'string', 'confirmed', Password::min($console ? 12 : 8)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [], ['password' => 'mot de passe']);

        $user = $this->invitations->accept($invitation, $data['password'] ?? null);
        // Le lien remplace l'accès au compte : les sessions ouvertes ailleurs avant lui sont fermées
        $user->tokens()->delete();
        TrustedDevice::query()->where('user_id', $user->id)->delete();

        // Console : pas de session ouverte par le lien, la connexion passe par le mot de passe et le code
        if ($console) {
            return response()->json(['login' => true, 'message' => 'Mot de passe enregistré : connectez-vous avec votre téléphone ou votre e-mail.']);
        }

        return response()->json(AuthController::session($user, $data['device_name'] ?? 'invitation'));
    }
}
