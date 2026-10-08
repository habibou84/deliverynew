<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ZoneResource;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ZoneController extends Controller
{
    /**
     * Zones de l'entreprise (communes puis quartiers), visibles par tous ses utilisateurs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $zones = Zone::query()
            ->with('parent')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderByRaw('COALESCE(parent_id, id)')
            ->orderByRaw('parent_id IS NOT NULL')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ZoneResource::collection($zones);
    }

    public function store(Request $request): ZoneResource
    {
        $data = $request->validate($this->rules($request));

        return ZoneResource::make(Zone::create($data)->load('parent'));
    }

    public function update(Request $request, Zone $zone): ZoneResource
    {
        $data = $request->validate($this->rules($request, $zone));

        $zone->update($data);

        return ZoneResource::make($zone->load('parent'));
    }

    private function rules(Request $request, ?Zone $zone = null): array
    {
        $required = $zone ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:100',
                Rule::unique('zones')->where('company_id', $request->user()->company_id)
                    ->where('parent_id', $request->input('parent_id', $zone?->parent_id))
                    ->ignore($zone)],
            // Un seul niveau de sous-zone : commune -> quartier
            'parent_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('zones', 'id')->where('company_id', $request->user()->company_id)->whereNull('parent_id'),
                Rule::notIn(array_filter([$zone?->id]))],
            'city' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'is_shipping' => ['sometimes', 'boolean'],
            'shipping_fee_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
