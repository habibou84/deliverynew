<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegacyCashBaseline;
use App\Services\Legacy\LegacyCashBaselines;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Argent chez les livreurs venu de l'ancienne application (Réglages › Reprise) : point de départ à une date, livreur
 * par livreur, annulable pendant 7 jours.
 */
class LegacyCashBaselineController extends Controller
{
    public function __construct(private readonly LegacyCashBaselines $baselines) {}

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $cutoff = $this->cutoff($request);

        return response()->json(['data' => [
            'cutoff' => $cutoff->toDateString(),
            'switch_date' => $company->legacy_switch_at?->toDateString(),
            ...$this->baselines->preview($company, $cutoff),
            'last' => $this->baselines->describe(LegacyCashBaseline::query()->where('company_id', $company->id)->with('user:id,name')->latest('id')->first()),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cutoff' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today()->addDay()->toDateString()],
            'amounts' => ['nullable', 'array'],
            'amounts.*' => ['integer', 'min:0'],
            'confirm' => ['required', 'string', Rule::in(['VALIDER'])],
        ], ['confirm.in' => 'Tapez VALIDER pour confirmer.'], ['confirm' => 'confirmation', 'cutoff' => 'date']);

        try {
            $baseline = $this->baselines->apply($request->user()->company, $request->user(), Carbon::parse($data['cutoff']),
                collect($data['amounts'] ?? [])->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v])->all());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->baselines->describe($baseline->load('user:id,name'))], 201);
    }

    public function undo(Request $request, LegacyCashBaseline $baseline): JsonResponse
    {
        abort_unless($baseline->company_id === $request->user()->company_id, 404);
        try {
            $restored = $this->baselines->undo($baseline);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [...$this->baselines->describe($baseline->fresh('user')), 'restored' => $restored]]);
    }

    private function cutoff(Request $request): Carbon
    {
        $value = $request->query('cutoff');
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::parse($value);
        }

        return $request->user()->company->legacy_switch_at?->copy()->startOfDay() ?? today();
    }
}
