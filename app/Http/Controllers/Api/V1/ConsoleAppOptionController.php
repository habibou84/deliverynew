<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppOption;
use App\Services\Platform\AppOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console › Formules › Demandes d'option : options « Applications à leur nom » demandées par les entreprises
 * (accepter, refuser, arrêter). Lecture pour l'équipe, décisions au Propriétaire.
 */
class ConsoleAppOptionController extends Controller
{
    public function __construct(private readonly AppOptions $options) {}

    public function index(): JsonResponse
    {
        $options = AppOption::query()->with(['company.plan', 'requester:id,name'])
            ->whereIn('status', [AppOption::PENDING, AppOption::ACTIVE])
            ->orWhere('updated_at', '>=', now()->subDays(30))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")->latest('id')->limit(200)->get();

        return response()->json([
            'data' => $options->map(fn (AppOption $o) => self::present($o))->values(),
            'meta' => ['pending' => $options->where('status', AppOption::PENDING)->count()],
        ]);
    }

    public function accept(Request $request, AppOption $option): JsonResponse
    {
        return response()->json(['data' => self::present($this->options->accept($option, $request->user())->load('company.plan'))]);
    }

    public function refuse(Request $request, AppOption $option): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']], [], ['note' => 'motif']);

        return response()->json(['data' => self::present($this->options->refuse($option, $request->user(), $data['note'] ?? null)->load('company.plan'))]);
    }

    public function stop(Request $request, AppOption $option): JsonResponse
    {
        return response()->json(['data' => self::present($this->options->stop($option, $request->user())->load('company.plan'))]);
    }

    /** @return array<string, mixed> */
    public static function present(AppOption $o): array
    {
        return [
            'id' => $o->id,
            'company' => ['id' => $o->company?->id, 'name' => $o->company?->name],
            'plan' => $o->company?->plan?->name,
            'platform' => $o->platform,
            'label' => 'Applications '.(AppOptions::LABELS[$o->platform] ?? $o->platform).' à son nom',
            'status' => $o->status,
            'price' => $o->price,
            'requested_by' => $o->requester?->name,
            'requested_at' => $o->created_at?->toIso8601String(),
            'started_at' => $o->started_at?->toIso8601String(),
            'stopped_at' => $o->stopped_at?->toIso8601String(),
            'note' => $o->note,
        ];
    }
}
