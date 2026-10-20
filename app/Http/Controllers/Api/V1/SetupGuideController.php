<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\SetupGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parcours de mise en route de l'administrateur (droit settings.manage).
 */
class SetupGuideController extends Controller
{
    public function __construct(private readonly SetupGuide $guide) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->guide->for($request->user()->company)]);
    }

    /**
     * Remettre une étape conseillée à plus tard, ou la reprendre.
     */
    public function skip(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $data = $request->validate([
            'step' => ['required', 'string', Rule::in($this->guide->recommendedKeys($company))],
            'skipped' => ['required', 'boolean'],
        ], [], ['step' => 'étape']);

        $skipped = collect($company->setup_skipped ?? [])->reject(fn ($k) => $k === $data['step']);
        $company->forceFill(['setup_skipped' => ($data['skipped'] ? $skipped->push($data['step']) : $skipped)->values()->all()])->save();

        return response()->json(['data' => $this->guide->for($company->fresh())]);
    }
}
