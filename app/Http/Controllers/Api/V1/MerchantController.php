<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreMerchantRequest;
use App\Http\Requests\V1\UpdateMerchantRequest;
use App\Http\Resources\V1\MerchantResource;
use App\Http\Resources\V1\UserResource;
use App\Models\Merchant;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class MerchantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Merchant::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'in:backoffice,signup'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            // Ceux des dernières courses d'abord (choix de l'e-commerçant à l'enregistrement d'une course)
            'recent' => ['nullable', 'boolean'],
            // Page E-commerçants : zone de ramassage, position connue ou non, tri, résumé
            'zone_id' => ['nullable', 'integer'],
            'located' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:name,orders,created_at'],
            'dir' => ['nullable', 'in:asc,desc'],
            'summary' => ['nullable', 'boolean'],
        ]);
        $dir = $request->input('dir') === 'desc' ? 'desc' : 'asc';

        $merchants = Merchant::query()
            ->with('pickupZone')
            ->withCount('orders')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Inscrits en ligne
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source'))->when(! $request->filled('sort'), fn ($q) => $q->latest()))
            ->when($request->filled('zone_id'), fn ($q) => $q->where('pickup_zone_id', $request->integer('zone_id')))
            ->when($request->filled('located'), fn ($q) => $request->boolean('located') ? $q->whereNotNull('pickup_lat') : $q->whereNull('pickup_lat'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower($request->string('search')).'%';
                $q->where(fn ($q) => $q->whereRaw('LOWER(business_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(contact_name) LIKE ?', [$term])
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->boolean('recent'), fn ($q) => $q->withMax('orders', 'created_at')->has('orders')->orderByDesc('orders_max_created_at'))
            ->when($request->input('sort') === 'orders', fn ($q) => $q->orderBy('orders_count', $dir))
            ->when($request->input('sort') === 'created_at', fn ($q) => $q->orderBy('created_at', $dir)->orderBy('id', $dir))
            ->orderBy('business_name', $request->input('sort') === 'name' ? $dir : 'asc')
            ->paginate(min(max($request->integer('per_page', 50), 1), 500));

        $collection = MerchantResource::collection($merchants);

        // Résumé de la page E-commerçants : chaque case filtre la liste
        if ($request->boolean('summary')) {
            $collection->additional(['summary' => [
                'merchants' => Merchant::query()->count(),
                'active' => Merchant::query()->where('status', 'active')->count(),
                'suspended' => Merchant::query()->where('status', '!=', 'active')->count(),
                'signups_new' => Merchant::query()->where('source', 'signup')->where('created_at', '>=', now()->subDays(7))->count(),
                'without_location' => Merchant::query()->where('status', 'active')->whereNull('pickup_lat')->count(),
            ]]);
        }

        return $collection;
    }

    public function store(StoreMerchantRequest $request): JsonResponse
    {
        $data = $request->validated();

        $merchant = DB::transaction(function () use ($data, $request) {
            $merchant = Merchant::create(Arr::except($data, 'owner'));
            self::trackLocationSource($merchant);

            if (! empty($data['owner'])) {
                $owner = User::create([
                    'company_id' => $request->user()->company_id,
                    'merchant_id' => $merchant->id,
                    'name' => $data['owner']['name'],
                    'phone' => $data['owner']['phone'],
                    'email' => $data['owner']['email'] ?? null,
                    'password' => $data['owner']['password'],
                ]);
                $owner->assignRole(Role::MerchantOwner->value);
            }

            return $merchant;
        });

        return MerchantResource::make($merchant->load(['pickupZone', 'users.roles']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Merchant $merchant): MerchantResource
    {
        Gate::authorize('view', $merchant);

        return MerchantResource::make($merchant->load(['pickupZone', 'users.roles'])->loadCount('orders'));
    }

    public function update(UpdateMerchantRequest $request, Merchant $merchant): MerchantResource
    {
        $merchant->update($request->validated());
        self::trackLocationSource($merchant);

        // Un marchand suspendu ne peut plus se connecter
        if ($merchant->wasChanged('status') && ! $merchant->isActive()) {
            foreach ($merchant->users as $user) {
                $user->tokens()->delete();
            }
        }

        return MerchantResource::make($merchant->load(['pickupZone', 'users.roles']));
    }

    /**
     * Position saisie ou retirée par l'agence (fiche ou API) : source et date.
     */
    private static function trackLocationSource(Merchant $merchant): void
    {
        if (! $merchant->wasChanged(['pickup_lat', 'pickup_lng']) && ! $merchant->wasRecentlyCreated) {
            return;
        }

        $merchant->forceFill($merchant->hasPickupLocation()
            ? ['pickup_location_source' => Merchant::LOCATION_STAFF, 'pickup_located_at' => now(), 'pickup_location_accuracy' => null]
            : ['pickup_location_source' => null, 'pickup_located_at' => null, 'pickup_location_accuracy' => null])->save();
    }

    /**
     * Ajoute un compte de connexion au marchand (gérant ou employé).
     */
    public function storeUser(Request $request, Merchant $merchant): JsonResponse
    {
        Gate::authorize('update', $merchant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber],
            'email' => ['nullable', 'email', 'max:255', User::uniqueIn($merchant->company_id, 'email')],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::in([Role::MerchantOwner->value, Role::MerchantStaff->value])],
        ]);

        $phone = Phone::normalize($data['phone']);
        if (User::takenIn($merchant->company_id, 'phone', $phone)) {
            return response()->json([
                'message' => 'Ce téléphone est déjà utilisé.',
                'errors' => ['phone' => ['Ce téléphone est déjà utilisé.']],
            ], 422);
        }

        $user = DB::transaction(function () use ($data, $merchant) {
            $user = User::create([
                ...Arr::except($data, 'role'),
                'company_id' => $merchant->company_id,
                'merchant_id' => $merchant->id,
            ]);
            $user->assignRole($data['role']);

            return $user;
        });

        return UserResource::make($user->load('roles'))->response()->setStatusCode(201);
    }
}
