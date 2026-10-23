<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreUserRequest;
use App\Http\Requests\V1\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounts\Invitations;
use App\Services\Platform\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $actor = $request->user();

        $request->validate([
            'company_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::in(Role::values(Role::cases()))],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['roles', 'company'])
            ->when(
                $actor->isSuperAdmin(),
                fn ($q) => $q->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id'))),
                fn ($q) => $q->forCompany($actor->company_id),
            )
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
            ->paginate($request->integer('per_page', 20));

        return UserResource::collection($users);
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

            return $user;
        });
        // Livreurs en plus de la formule : comptés dès le jour où ils sont actifs
        if ($data['role'] === Role::Courier->value && $user->company) {
            $subscription->recordCouriers($user->company);
        }

        $response = UserResource::make($user->load(['roles', 'company']))->additional(
            // Sans mot de passe : lien d'invitation, que l'administrateur envoie lui-même par WhatsApp
            $user->password === null ? ['invitation' => $invitations->create($user, $actor)] : [],
        );

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

        DB::transaction(function () use ($user, $data) {
            $user->fill(collect($data)->except('role')->all())->save();

            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
                $user->syncCourierProfile();
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
