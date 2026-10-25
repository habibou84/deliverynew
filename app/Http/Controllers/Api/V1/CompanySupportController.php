<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\SupportSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Espace de l'entreprise : les ouvertures de son espace par l'assistance Jibiat (qui, quand, pourquoi, combien
 * d'actions), et celle en cours.
 */
class CompanySupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = SupportSession::query()->where('company_id', $request->user()->company_id)->with('opener:id,name')
            ->latest('id')->limit(30)->get();

        $data = $sessions->map(fn (SupportSession $s) => [
            'id' => $s->id,
            'opened_by' => $s->opener?->name ?? 'Assistance Jibiat',
            'reason' => $s->reason,
            'created_at' => $s->created_at?->toIso8601String(),
            'expires_at' => $s->expires_at->toIso8601String(),
            'open' => $s->expires_at->isFuture(),
            'actions' => PlatformAuditLog::query()->where('company_id', $s->company_id)->where('user_id', $s->opened_by)
                ->whereBetween('created_at', [$s->created_at, $s->expires_at])->count(),
        ])->values();

        return response()->json(['data' => $data, 'active' => $data->firstWhere('open', true)]);
    }
}
