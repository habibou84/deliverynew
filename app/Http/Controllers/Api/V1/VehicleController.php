<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VehicleDocumentResource;
use App\Http\Resources\V1\VehicleResource;
use App\Models\Courier;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\Vehicles\VehicleFleet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Parc des véhicules : fiches, affectation aux livreurs (avec l'historique), photos et papiers avec leur date de
 * fin (carte grise, assurance, visite technique, vignette ; permis des livreurs).
 */
class VehicleController extends Controller
{
    public function __construct(private readonly VehicleFleet $fleet) {}

    public function index(Request $request): JsonResponse
    {
        $vehicles = Vehicle::query()->with(['courier.user', 'documents'])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'repair' THEN 1 ELSE 2 END")->orderBy('plate')->get();

        // Livreurs actifs : à qui confier un véhicule, et leur permis
        $couriers = Courier::query()->with(['user', 'documents', 'vehicle'])
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))->get()
            ->sortBy(fn (Courier $c) => mb_strtolower((string) $c->user?->name))->values()
            ->map(fn (Courier $c) => [
                'id' => $c->id,
                'name' => $c->user?->name,
                'phone' => $c->user?->phone,
                'vehicle_type' => $c->vehicle_type?->value,
                'vehicle_id' => $c->vehicle?->id,
                'documents' => VehicleDocumentResource::collection(VehicleFleet::current($c->documents)->values()),
            ]);

        return response()->json([
            'data' => VehicleResource::collection($vehicles),
            'couriers' => $couriers,
            'options' => [
                'types' => Vehicle::TYPES,
                'owners' => Vehicle::OWNERS,
                'statuses' => Vehicle::STATUSES,
                'vehicle_documents' => VehicleDocument::VEHICLE_TYPES,
                'courier_documents' => VehicleDocument::COURIER_TYPES,
                'warn_days' => VehicleDocument::WARN_DAYS,
            ],
        ]);
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        return new VehicleResource($vehicle->load(['courier.user', 'documents', 'assignments' => fn ($q) => $q->with('courier.user')->latest('started_at')]));
    }

    public function store(Request $request): JsonResponse
    {
        $vehicle = Vehicle::create($this->validated($request));
        if ($request->filled('courier_id')) {
            $this->fleet->assign($vehicle, $this->courier($request), $request->user());
        }

        return (new VehicleResource($vehicle->load(['courier.user', 'documents'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Vehicle $vehicle): VehicleResource
    {
        $vehicle->update($this->validated($request, $vehicle));
        // Retiré du parc : il n'est plus confié à personne
        if ($vehicle->status === 'retired' && $vehicle->courier_id !== null) {
            $this->fleet->assign($vehicle, null, $request->user());
        }
        $this->fleet->synced($vehicle);

        return new VehicleResource($vehicle->load(['courier.user', 'documents']));
    }

    /** Confier le véhicule à un livreur, ou le reprendre (courier_id vide) */
    public function assign(Request $request, Vehicle $vehicle): VehicleResource
    {
        $request->validate(['courier_id' => ['nullable', 'integer']]);
        $courier = $request->filled('courier_id') ? $this->courier($request) : null;
        abort_if($courier !== null && $vehicle->status === 'retired', 422, 'Ce véhicule est retiré du parc : remettez-le en service avant de le confier.');

        return new VehicleResource($this->fleet->assign($vehicle, $courier, $request->user())->load(['courier.user', 'documents']));
    }

    public function photo(Request $request, Vehicle $vehicle): VehicleResource
    {
        $request->validate(['file' => ['required', 'image', 'max:5120']], [], ['file' => 'photo']);
        $old = $vehicle->photo_path;
        $vehicle->update(['photo_path' => $request->file('file')->store("vehicles/{$vehicle->company_id}", 'local')]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return new VehicleResource($vehicle->load(['courier.user', 'documents']));
    }

    public function photoFile(Vehicle $vehicle): StreamedResponse
    {
        abort_unless($vehicle->photo_path && Storage::disk('local')->exists($vehicle->photo_path), 404);

        return Storage::disk('local')->response($vehicle->photo_path);
    }

    /** Papier d'un véhicule (vehicle_id) ou permis d'un livreur (courier_id), avec sa photo ou son PDF */
    public function storeDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required_without:courier_id', 'nullable', 'integer', Rule::exists('vehicles', 'id')->where('company_id', $request->user()->company_id)],
            'courier_id' => ['required_without:vehicle_id', 'nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
            ...$this->documentRules($request),
        ], [], $this->documentNames());
        abort_if(filled($data['vehicle_id'] ?? null) === filled($data['courier_id'] ?? null), 422, 'Choisissez le véhicule ou le livreur.');
        $types = filled($data['vehicle_id'] ?? null) ? VehicleDocument::VEHICLE_TYPES : VehicleDocument::COURIER_TYPES;
        abort_unless(isset($types[$data['type']]), 422, 'Ce papier ne correspond pas.');

        $document = VehicleDocument::create([
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'courier_id' => $data['courier_id'] ?? null,
            ...$this->documentFields($request, $data),
        ]);

        return (new VehicleDocumentResource($document))->response()->setStatusCode(201);
    }

    public function updateDocument(Request $request, VehicleDocument $document): VehicleDocumentResource
    {
        $data = $request->validate($this->documentRules($request), [], $this->documentNames());
        $types = $document->vehicle_id ? VehicleDocument::VEHICLE_TYPES : VehicleDocument::COURIER_TYPES;
        abort_unless(isset($types[$data['type']]), 422, 'Ce papier ne correspond pas.');

        $fields = $this->documentFields($request, $data);
        // Nouvelle date de fin : les rappels repartent
        if (($fields['expires_at'] ?? null) !== $document->expires_at?->toDateString()) {
            $fields += ['warned_at' => null, 'expired_warned_at' => null];
        }
        if (isset($fields['file_path']) && $document->file_path) {
            Storage::disk('local')->delete($document->file_path);
        }
        $document->update($fields);

        return new VehicleDocumentResource($document);
    }

    public function destroyDocument(VehicleDocument $document): JsonResponse
    {
        if ($document->file_path) {
            Storage::disk('local')->delete($document->file_path);
        }
        $document->delete();

        return response()->json(['message' => 'Papier supprimé.']);
    }

    public function documentFile(VehicleDocument $document): StreamedResponse
    {
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->response($document->file_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Vehicle $vehicle = null): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Vehicle::TYPES))],
            'plate' => ['nullable', 'string', 'max:30'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:40'],
            'owner' => ['nullable', Rule::in(array_keys(Vehicle::OWNERS))],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(array_keys(Vehicle::STATUSES))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'courier_id' => ['nullable', 'integer'],
        ], [], ['plate' => 'immatriculation', 'brand' => 'marque', 'model' => 'modèle', 'color' => 'couleur', 'owner' => 'propriétaire', 'owner_name' => 'nom du propriétaire']);
        unset($data['courier_id']);

        $data['plate'] = filled($data['plate'] ?? null) ? mb_strtoupper(preg_replace('/\s+/', ' ', trim($data['plate']))) : null;
        if ($data['plate'] !== null) {
            $taken = Vehicle::query()->where('plate', $data['plate'])->when($vehicle, fn ($q) => $q->whereKeyNot($vehicle->id))->exists();
            abort_if($taken, 422, "Un véhicule immatriculé {$data['plate']} existe déjà.");
        }
        if (($data['owner'] ?? null) === 'company') {
            $data['owner_name'] = null;
        }

        return $data;
    }

    private function courier(Request $request): Courier
    {
        return Courier::query()->whereKey($request->integer('courier_id'))->firstOr(fn () => abort(422, 'Livreur introuvable.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRules(Request $request): array
    {
        return [
            'type' => ['required', 'string', 'max:30'],
            'number' => ['nullable', 'string', 'max:60'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function documentNames(): array
    {
        return ['type' => 'papier', 'number' => 'numéro', 'expires_at' => 'date de fin', 'file' => 'photo ou PDF', 'vehicle_id' => 'véhicule', 'courier_id' => 'livreur'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function documentFields(Request $request, array $data): array
    {
        $fields = [
            'type' => $data['type'],
            'number' => $data['number'] ?? null,
            'expires_at' => filled($data['expires_at'] ?? null) ? date('Y-m-d', strtotime($data['expires_at'])) : null,
            'notes' => $data['notes'] ?? null,
        ];
        if ($request->hasFile('file')) {
            $fields['file_path'] = $request->file('file')->store("vehicle-documents/{$request->user()->company_id}", 'local');
            $fields['file_mime'] = $request->file('file')->getMimeType();
        }

        return $fields;
    }
}
