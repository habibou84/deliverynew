<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreUserRequest;
use App\Http\Requests\V1\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\Company;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Services\Accounts\Invitations;
use App\Services\Platform\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** Sans connexion depuis ce nombre de jours : compte « à vérifier » */
    public const INACTIVE_DAYS = 30;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();

        $request->validate([
            'company_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::in(Role::values(Role::cases()))],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            // Onglets de la page Utilisateurs
            'scope' => ['nullable', Rule::in(['team', 'couriers', 'merchants', 'suspended', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['roles', 'company', 'courier', 'merchant'])
            ->when(
                $actor->isSuperAdmin(),
                fn ($q) => $q->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id'))),
                fn ($q) => $q->forCompany($actor->company_id),
            )
            ->when($request->filled('scope'), fn ($q) => self::scope($q, $request->string('scope')->toString()))
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')->toString()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower($request->string('search')).'%';
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 20), 1), 500));

        return UserResource::collection($users);
    }

    /**
     * Onglets : équipe, livreurs, comptes e-commerçants, suspendus, et « à vérifier » (équipe et livreurs actifs
     * jamais connectés ou sans connexion depuis 30 jours : des accès ouverts qui ne servent pas).
     */
    private static function scope(Builder $query, string $scope): Builder
    {
        $roles = fn (array $roles) => Role::values($roles);

        return match ($scope) {
            'team' => $query->role($roles(Role::staff())),
            'couriers' => $query->role(Role::Courier->value),
            'merchants' => $query->role([Role::MerchantOwner->value, Role::MerchantStaff->value]),
            'suspended' => $query->where('status', UserStatus::Suspended),
            'inactive' => $query->role($roles([...Role::staff(), Role::Courier]))
                ->where('status', UserStatus::Active)
                ->where(fn ($q) => $q->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(self::INACTIVE_DAYS))),
            default => $query,
        };
    }

    /**
     * Compteurs de la page Utilisateurs (entreprise de la personne connectée).
     */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        $companyId = $request->user()->company_id;
        abort_if($companyId === null, 422, 'Réservé aux entreprises.');

        $count = fn (string $scope) => self::scope(User::query()->forCompany($companyId), $scope)->count();
        $byRole = User::query()->forCompany($companyId)
            ->join('model_has_roles', fn ($j) => $j->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', (new User)->getMorphClass()))
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->selectRaw('roles.name as role, users.status as status, count(*) as total')
            ->groupBy('roles.name', 'users.status')
            ->get();
        $roles = [];
        foreach ($byRole as $row) {
            $status = $row->status instanceof UserStatus ? $row->status->value : (string) $row->status;
            $roles[$row->role][$status] = ($roles[$row->role][$status] ?? 0) + (int) $row->total;
        }

        return response()->json(['data' => [
            'team' => $count('team'),
            'couriers' => $count('couriers'),
            'merchants' => $count('merchants'),
            'suspended' => $count('suspended'),
            'inactive' => $count('inactive'),
            'inactive_days' => self::INACTIVE_DAYS,
            // Par rôle : { dispatcher: { active: 4, suspended: 1 }, … }
            'roles' => $roles,
        ]]);
    }

    /**
     * Ferme toutes les sessions d'un compte (téléphone perdu, départ…) sans le suspendre.
     */
    public function logout(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->is($user), 422, 'Pour vous déconnecter, utilisez « Se déconnecter » dans votre profil.');
        Gate::authorize('changeCredentials', $user);

        $user->tokens()->delete();
        TrustedDevice::query()->where('user_id', $user->id)->delete();
        UserAccountEvent::record($user, $request->user(), UserAccountEvent::LOGGED_OUT);

        return response()->json(['message' => "{$user->name} est déconnecté de tous ses appareils."]);
    }

    /**
     * Historique du compte : qui l'a créé, qui a changé son rôle, ses accès, son statut.
     */
    public function history(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        $events = UserAccountEvent::query()->where('user_id', $user->id)->with('actor:id,name')->latest('id')->limit(100)->get();

        return response()->json(['data' => $events->map(fn (UserAccountEvent $e) => [
            'id' => $e->id,
            'action' => $e->action,
            'text' => $e->text(),
            'by' => $e->actor?->name,
            'at' => $e->created_at,
        ])->values()]);
    }

    public function store(StoreUserRequest $request, Subscription $subscription, Invitations $invitations): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        // Nombre de livreurs actifs limité par la formule de l'entreprise
        if ($data['role'] === Role::Courier->value) {
            $subscription->ensureCourierRoom(Company::findOrFail($actor->isSuperAdmin() ? $data['company_id'] : $actor->company_id));
        }

        $user = DB::transaction(function () use ($actor, $data) {
            $user = User::create([
                'company_id' => $actor->isSuperAdmin() ? $data['company_id'] : $actor->company_id,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'password' => filled($data['password'] ?? null) ? $data['password'] : null,
            ]);

            $user->assignRole($data['role']);
            $user->syncCourierProfile();
            UserAccountEvent::record($user, $actor, UserAccountEvent::CREATED, ['role' => $data['role']]);

            return $user;
        });
        // Livreurs en plus de la formule : comptés dès le jour où ils sont actifs
        if ($data['role'] === Role::Courier->value && $user->company) {
            $subscription->recordCouriers($user->company);
        }

        // Sans mot de passe : lien d'invitation, que l'administrateur envoie lui-même par WhatsApp
        $invitation = null;
        if ($user->password === null) {
            $invitation = $invitations->create($user, $actor);
            UserAccountEvent::record($user, $actor, UserAccountEvent::INVITATION_SENT);
        }
        $response = UserResource::make($user->load(['roles', 'company']))->additional($invitation ? ['invitation' => $invitation] : []);

        return $response->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return UserResource::make($user->load(['roles', 'company']));
    }

    public function update(UpdateUserRequest $request, User $user, Subscription $subscription): UserResource
    {
        $data = $request->validated();

        // Livreur réactivé ou nouveau livreur : dans la limite de la formule
        $wasCounted = $user->hasRole(Role::Courier->value) && $user->status === UserStatus::Active;
        $isCourier = isset($data['role']) ? $data['role'] === Role::Courier->value : $user->hasRole(Role::Courier->value);
        $isActive = isset($data['status']) ? $data['status'] === UserStatus::Active->value : $user->status === UserStatus::Active;
        if ($isCourier && $isActive && ! $wasCounted && $user->loadMissing('company')->company) {
            $subscription->ensureCourierRoom($user->company, isset($data['role']) ? 'role' : 'status');
        }

        $actor = $request->user();
        $before = ['name' => $user->name, 'role' => $user->primaryRole()?->value];

        DB::transaction(function () use ($user, $data, $actor, $before) {
            $user->fill(collect($data)->except('role')->all())->save();

            if (isset($data['role']) && $data['role'] !== $before['role']) {
                $user->syncRoles([$data['role']]);
                $user->syncCourierProfile();
                UserAccountEvent::record($user, $actor, UserAccountEvent::ROLE_CHANGED, ['from' => $before['role'], 'to' => $data['role']]);
            }
            if ($user->wasChanged('status')) {
                UserAccountEvent::record($user, $actor, $user->status === UserStatus::Suspended ? UserAccountEvent::SUSPENDED : UserAccountEvent::REACTIVATED);
            }
            if ($user->wasChanged('name')) {
                UserAccountEvent::record($user, $actor, UserAccountEvent::RENAMED, ['from' => $before['name'], 'to' => $user->name]);
            }
            $credentials = array_values(array_filter(['phone', 'email', 'password'], fn ($f) => $user->wasChanged($f)));
            if ($credentials) {
                UserAccountEvent::record($user, $actor, UserAccountEvent::CREDENTIALS_CHANGED, ['fields' => $credentials]);
            }

            // Un compte suspendu ou dont le mot de passe change est déconnecté partout
            if ($user->wasChanged(['status', 'password'])) {
                $user->tokens()->delete();
            }
        });
        if ($isCourier && $isActive && ! $wasCounted && $user->company) {
            $subscription->recordCouriers($user->company);
        }

        return UserResource::make($user->load(['roles', 'company']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->is($user), 403, 'Vous ne pouvez pas supprimer votre propre compte.');
        Gate::authorize('delete', $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(null, 204);
    }
}
