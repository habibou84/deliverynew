<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreCompanyRequest;
use App\Http\Requests\V1\UpdateCompanyRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Company::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companies = Company::query()
            ->withCount('users')
            ->when($request->filled('search'), fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($request->string('search')).'%']))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return CompanyResource::collection($companies);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $data = $request->validated();

        $company = DB::transaction(function () use ($data) {
            $company = Company::create([
                ...collect($data)->except('admin')->all(),
                'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
            ]);

            if (! empty($data['admin'])) {
                User::create([
                    'company_id' => $company->id,
                    'name' => $data['admin']['name'],
                    'phone' => $data['admin']['phone'],
                    'email' => $data['admin']['email'] ?? null,
                    'password' => $data['admin']['password'],
                ])->assignRole(Role::Admin->value);
            }

            return $company;
        });

        return CompanyResource::make($company->loadCount('users'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        return CompanyResource::make($company->loadCount('users'));
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $company->update($request->validated());

        return CompanyResource::make($company->loadCount('users'));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'entreprise';
        $slug = $base;
        $i = 2;

        while (Company::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
