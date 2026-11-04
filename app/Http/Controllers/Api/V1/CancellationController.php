<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CancellationRequest;
use App\Models\Order;
use App\Services\Orders\Cancellations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Courses › Annulations : point des annulations (par e-commerçant, par personne, par motif), demandes à valider,
 * règles de l'entreprise ; motifs et quota du jour pour la fenêtre d'annulation.
 */
class CancellationController extends Controller
{
    public function __construct(private readonly Cancellations $cancellations) {}

    /** Motifs de la personne connectée et ses annulations du jour (fenêtre d'annulation) */
    public function quota(Request $request): JsonResponse
    {
        $user = $request->user();
        $reasons = Cancellations::reasons($user->merchant_id !== null);

        return response()->json(['data' => [
            ...$this->cancellations->quota($user),
            'reasons' => collect($reasons)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
        ]]);
    }

    public function report(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = $request->date('to') ?? today();
        $from = $request->date('from') ?? $to->copy()->subDays(6);
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        $pending = CancellationRequest::query()->where('status', CancellationRequest::PENDING)
            ->with(['order:id,tracking_code,merchant_id,status', 'order.merchant:id,business_name', 'requester:id,name'])
            ->latest('id')->get();

        return response()->json(['data' => [
            ...$this->cancellations->report($request->user()->company, $from, $to),
            'is_admin' => Cancellations::isAdmin($request->user()),
            'requests' => $pending->map(fn (CancellationRequest $r) => [
                'id' => $r->id,
                'order_id' => $r->order_id,
                'tracking_code' => $r->order?->tracking_code,
                'merchant' => $r->order?->merchant?->business_name,
                'to_status' => $r->to_status->value,
                'requested_by' => $r->requester?->name,
                'reason' => Cancellations::label($r->reason_code, 'company'),
                'note' => $r->note,
                'created_at' => $r->created_at,
            ])->values(),
        ]]);
    }

    public function decide(Request $request, CancellationRequest $cancellationRequest): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $decided = $this->cancellations->decide($request->user(), $cancellationRequest, $data['approve'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $decided->id, 'status' => $decided->status], 'message' => match ($decided->status) {
            CancellationRequest::APPROVED => 'Annulation validée.',
            CancellationRequest::REFUSED => 'Demande refusée : la course continue.',
            default => 'La course a avancé entre-temps : elle ne peut plus être annulée.',
        }]);
    }

    public function updateRules(Request $request): JsonResponse
    {
        abort_unless(Cancellations::isAdmin($request->user()), 403, 'Réservé aux administrateurs.');
        $data = $request->validate([
            'staff_daily_limit' => ['present', 'nullable', 'integer', 'min:0', 'max:500'],
            'hourly_alert' => ['present', 'nullable', 'integer', 'min:1', 'max:500'],
            'merchant_rate_alert' => ['present', 'nullable', 'integer', 'min:1', 'max:100'],
            'weekly_report' => ['required', 'boolean'],
        ], [], ['staff_daily_limit' => 'limite par jour', 'hourly_alert' => 'seuil par heure', 'merchant_rate_alert' => 'taux d\'annulation']);
        $company = $request->user()->company;
        $company->forceFill(['cancel_rules' => $data])->save();

        return response()->json(['data' => Cancellations::rules($company)]);
    }
}
