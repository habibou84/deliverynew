<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\JibiatAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Page « Jibiat IA » de l'entreprise : niveau compris dans sa formule, consommation du mois, recharges. Plus d'essai ni
 * d'abonnement à part (refusés) ; un ancien abonnement à part peut encore être arrêté ou continué.
 */
class JibiatAiController extends Controller
{
    public function __construct(private readonly JibiatAi $ai) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ai->state($request->user()->company->loadMissing('plan'))]);
    }

    public function trial(Request $request): JsonResponse
    {
        $data = $request->validate(['tier' => ['required', Rule::in(array_keys(JibiatAi::TIER_DEFAULTS))]]);
        $company = $this->ai->startTrial($request->user()->company, $data['tier']);

        return response()->json(['data' => $this->ai->state($company->loadMissing('plan'))]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tier' => ['required', Rule::in(array_keys(JibiatAi::TIER_DEFAULTS))],
            'period' => ['required', Rule::in([JibiatAi::MONTHLY, JibiatAi::YEARLY])],
        ]);
        $company = $this->ai->subscribe($request->user()->company, $data['tier'], $data['period']);

        return response()->json(['data' => $this->ai->state($company->loadMissing('plan'))]);
    }

    public function stop(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ai->state($this->ai->stop($request->user()->company)->loadMissing('plan'))]);
    }

    public function resume(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ai->state($this->ai->resume($request->user()->company)->loadMissing('plan'))]);
    }

    public function recharge(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', Rule::in(JibiatAi::RECHARGES)]]);
        $company = $request->user()->company;
        $this->ai->recharge($company, (int) $data['amount'], $request->user());

        return response()->json(['data' => $this->ai->state($company->fresh()->loadMissing('plan'))]);
    }
}
