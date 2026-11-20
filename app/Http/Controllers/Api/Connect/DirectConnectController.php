<?php

namespace App\Http\Controllers\Api\Connect;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ConnectRequest;
use App\Rules\PhoneNumber;
use App\Services\Connect\DirectConnect;
use App\Support\Branding;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connexion directe de Jibiat Marchand (contrat socle 1.12), de serveur à serveur, sur l'adresse de l'entreprise :
 * ouvrir le compte d'une boutique, suivre ou annuler la demande. Requêtes signées (VerifyPartnerSignature).
 */
class DirectConnectController extends Controller
{
    public function __construct(private readonly DirectConnect $direct) {}

    public function store(Request $request): JsonResponse
    {
        $company = $this->company();
        if (is_string($request->input('owner.phone'))) {
            $request->merge(['owner' => [...$request->input('owner'), 'phone' => Phone::normalize($request->input('owner.phone')) ?? $request->input('owner.phone')]]);
        }

        $data = $request->validate([
            'client_id' => ['required', 'string', 'in:'.DirectConnect::CLIENT],
            'request_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'store' => ['required', 'array'],
            'store.reference' => ['required', 'string', 'max:60'],
            'store.name' => ['required', 'string', 'max:255'],
            'store.url' => ['nullable', 'string', 'max:500'],
            'store.city' => ['nullable', 'string', 'max:120'],
            'owner' => ['required', 'array'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.phone' => ['required', 'string', new PhoneNumber],
            // Seul un téléphone vérifié par Jibiat Marchand est accepté
            'owner.phone_verified_at' => ['required', 'date'],
            'owner.email' => ['nullable', 'email', 'max:255'],
            'pickup' => ['nullable', 'array'],
            'pickup.place' => ['nullable', 'string', 'max:120'],
            'pickup.address' => ['nullable', 'string', 'max:500'],
            'consent_at' => ['required', 'date'],
            'waiting_orders' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'decide_before' => ['nullable', 'date'],
            'activity' => ['nullable', 'array'],
            'activity.orders_30d' => ['nullable', 'integer', 'min:0'],
            'activity.since' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        [$connectRequest, $created] = $this->direct->open($company, $data);

        return response()->json(['data' => $this->direct->present($connectRequest)], $created ? 201 : 200);
    }

    public function show(string $requestId): JsonResponse
    {
        return response()->json(['data' => $this->direct->present($this->find($requestId))]);
    }

    public function destroy(string $requestId): JsonResponse
    {
        return response()->json(['data' => $this->direct->present($this->direct->cancel($this->find($requestId)))]);
    }

    private function find(string $requestId): ConnectRequest
    {
        $request = ConnectRequest::forCompany($this->company()->id)->where('request_id', $requestId)->first();
        abort_if($request === null, 404, 'Demande inconnue.');

        return $request;
    }

    private function company(): Company
    {
        $company = Branding::company();
        abort_if($company === null || ! $company->isActive(), 404, 'Aucune entreprise à cette adresse.');

        return $company;
    }
}
