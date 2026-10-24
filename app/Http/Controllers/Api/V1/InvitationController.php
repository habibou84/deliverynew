<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        Gate::authorize('update', $user);
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
            'expires_at' => $invitation->expires_at,
        ]]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->invitations->find($token);
        abort_if($invitation === null, 404, "Cette invitation n'est plus valable. Demandez-en une nouvelle à votre administrateur.");

        $data = $request->validate([
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [], ['password' => 'mot de passe']);

        $user = $this->invitations->accept($invitation, $data['password'] ?? null);

        return response()->json(AuthController::session($user, $data['device_name'] ?? 'invitation'));
    }
}
