<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console : journal des actions des super administrateurs, les plus récentes d'abord.
 */
class ConsoleAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = PlatformAuditLog::query()->with(['user:id,name,phone', 'company:id,name'])
            ->when($filters['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', now()->parse($to)->addDay()))
            ->latest('id')->paginate(50);

        return response()->json([
            'data' => $logs->getCollection()->map(fn (PlatformAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'method' => $log->method,
                'path' => $log->path,
                'status' => $log->status,
                'data' => $log->data,
                'ip' => $log->ip,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'company' => $log->company ? ['id' => $log->company->id, 'name' => $log->company->name] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}
