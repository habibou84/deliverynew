<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\ConsoleInvitation;
use App\Rules\PhoneNumber as PhoneRule;
use App\Services\Accounts\Invitations;
use App\Services\Platform\WaveSettings;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Console : équipe de la plateforme (Propriétaires, Support, Comptables). Invitation par lien (mot de passe choisi
 * par la personne, puis code de connexion par e-mail), changement de rôle, suspension. Il reste toujours au moins
 * un Propriétaire actif ; personne ne change son propre rôle ni ne se suspend.
 */
class ConsoleTeamController extends Controller
{
    public function __construct(private readonly Invitations $invitations) {}

    public function index(Request $request): JsonResponse
    {
        $members = User::query()->whereNull('company_id')->role(Role::values(Role::platform()))->with('roles')->orderBy('name')->get();
        $pending = PhoneVerification::query()->whereIn('user_id', $members->pluck('id'))->where('purpose', PhoneVerification::INVITE)
            ->pending()->pluck('expires_at', 'user_id');

        return response()->json(['data' => $members->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => PhoneNumber::display($u->phone),
            'email' => $u->email,
            'role' => $u->primaryRole()?->value,
            'role_label' => $u->primaryRole()?->label(),
            'status' => $u->status,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'invited' => $u->password === null,
            'invitation_expires_at' => isset($pending[$u->id]) ? $pending[$u->id]?->toIso8601String() : null,
            'me' => $u->is($request->user()),
        ])->values()]);
    }

    public function invite(Request $request): JsonResponse
    {
        $this->normalize($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneRule, User::uniqueIn(null, 'phone')],
            // L'e-mail reçoit le code de connexion : obligatoire pour l'équipe de la console
            'email' => ['required', 'email', 'max:190', User::uniqueIn(null, 'email')],
            'role' => ['required', Rule::in(Role::values(Role::platform()))],
        ], [], ['name' => 'nom', 'phone' => 'téléphone', 'email' => 'e-mail', 'role' => 'rôle']);

        $member = DB::transaction(function () use ($data) {
            $user = new User;
            $user->forceFill(['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'], 'password' => null, 'company_id' => null, 'status' => 'active'])->save();
            $user->assignRole($data['role']);

            return $user;
        });

        return response()->json(['data' => $this->sendInvitation($member, $request->user())], 201);
    }

    /** Nouveau lien (lien expiré, ou mot de passe à redéfinir) */
    public function reinvite(Request $request, User $member): JsonResponse
    {
        $this->ensureTeamMember($member);

        return response()->json(['data' => $this->sendInvitation($member, $request->user())]);
    }

    public function update(Request $request, User $member): JsonResponse
    {
        $this->ensureTeamMember($member);
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(Role::values(Role::platform()))],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ]);
        if ($member->is($request->user())) {
            throw ValidationException::withMessages(['member' => 'Vous ne pouvez pas changer votre propre rôle ni vous suspendre.']);
        }

        $losesOwner = $member->isSuperAdmin()
            && ((isset($data['role']) && $data['role'] !== Role::SuperAdmin->value) || ($data['status'] ?? null) === 'suspended');
        if ($losesOwner && User::query()->whereNull('company_id')->where('status', 'active')->role(Role::SuperAdmin->value)->whereKeyNot($member->id)->doesntExist()) {
            throw ValidationException::withMessages(['member' => 'Il doit rester au moins un Propriétaire actif.']);
        }

        DB::transaction(function () use ($member, $data) {
            if (isset($data['role'])) {
                $member->syncRoles([$data['role']]);
            }
            if (isset($data['status'])) {
                $member->forceFill(['status' => $data['status']])->save();
            }
            // Rôle retiré ou compte suspendu : déconnecté partout, le code sera redemandé
            if (isset($data['role']) || ($data['status'] ?? null) === 'suspended') {
                $member->tokens()->delete();
                TrustedDevice::query()->where('user_id', $member->id)->delete();
            }
        });

        return $this->index($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendInvitation(User $member, User $by): array
    {
        $invitation = $this->invitations->create($member, $by);
        $mailed = false;
        $mailer = app(WaveSettings::class)->mailer();
        if ($mailer !== null && filled($member->email)) {
            try {
                Notification::route('mail', $member->email)->notifyNow(new ConsoleInvitation($member->name, $by->name, $member->primaryRole()?->label() ?? '', $invitation['url'], $mailer['mailer'], $mailer['from']));
                $mailed = true;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [
            'id' => $member->id,
            'url' => $invitation['url'],
            'whatsapp_link' => $invitation['whatsapp_link'],
            'expires_at' => $invitation['expires_at']->toIso8601String(),
            'mailed' => $mailed,
        ];
    }

    private function ensureTeamMember(User $member): void
    {
        abort_unless($member->isPlatformStaff(), 404);
    }

    private function normalize(Request $request): void
    {
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone')) ?? $request->input('phone')]);
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
    }
}
