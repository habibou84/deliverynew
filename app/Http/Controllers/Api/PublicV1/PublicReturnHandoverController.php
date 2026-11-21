<?php

namespace App\Http\Controllers\Api\PublicV1;

use App\Http\Controllers\Controller;
use App\Models\ReturnHandover;
use App\Services\Orders\ReturnHandovers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remise des retours dans l'application du marchand (Jibiat Marchand), contrat socle 1.14 : remises encore
 * confirmables, « Tout reçu » et « Il en manque ». Mêmes règles que le bandeau de l'espace marchand.
 */
class PublicReturnHandoverController extends Controller
{
    public function __construct(private readonly ReturnHandovers $handovers) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->handovers->awaitingMerchant($user->company_id, $user->merchant_id)
            ->map(fn (ReturnHandover $h) => $h->toPublic())->values()]);
    }

    public function confirm(Request $request, string $reference): JsonResponse
    {
        return response()->json(['data' => $this->handovers->confirm($this->find($request, $reference), 'jibiat_marchand')->toPublic()]);
    }

    public function dispute(Request $request, string $reference): JsonResponse
    {
        return response()->json(['data' => $this->handovers->dispute($this->find($request, $reference))->toPublic()]);
    }

    /** Remise récente du marchand avec ce code (une remise contestée peut encore être confirmée) */
    private function find(Request $request, string $reference): ReturnHandover
    {
        $user = $request->user();

        return ReturnHandover::query()->where('company_id', $user->company_id)->where('merchant_id', $user->merchant_id)
            ->where('reference', mb_strtoupper($reference))
            ->where('updated_at', '>=', now()->subDays(2))
            ->latest('id')->firstOrFail();
    }
}
