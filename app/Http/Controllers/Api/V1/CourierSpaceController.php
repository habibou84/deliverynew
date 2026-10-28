<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\EarningType;
use App\Enums\OrderEventType;
use App\Events\CourierLocationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CourierResource;
use App\Http\Resources\V1\OrderAssignmentResource;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierMessage;
use App\Models\OrderAssignment;
use App\Models\OrderEvent;
use App\Services\Assignment\AutoAssigner;
use App\Services\Couriers\CourierTracker;
use App\Services\Couriers\ParcelRelays;
use App\Services\Finance\CourierPay;
use App\Services\Orders\OrderChanges;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Espace du livreur connecté : ses missions, sa disponibilité, sa position.
 */
class CourierSpaceController extends Controller
{
    public function missions(Request $request, CourierPay $pay): AnonymousResourceCollection
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');
        $courier->touchSeen();

        $assignments = OrderAssignment::query()
            ->where('courier_id', $courier->id)
            ->when(
                $request->boolean('history'),
                fn ($q) => $q->whereNotIn('status', ['assigned', 'accepted', 'in_progress'])->whereDate('updated_at', today()),
                fn ($q) => $q->active(),
            )
            // Frais déjà déclarés par ce livreur sur la course
            ->with(['order' => fn ($q) => $q->with([
                ...OrderController::LIST_RELATIONS,
                'expenses' => fn ($e) => $e->active()->where('courier_id', $courier->id)->orderBy('id'),
            ])])
            ->orderByRaw("CASE type WHEN 'pickup' THEN 0 WHEN 'delivery' THEN 1 ELSE 2 END")
            ->orderBy('assigned_at')
            ->get();

        // Consignes de l'agence non lues, par course
        $unread = CourierMessage::where('courier_id', $courier->id)->whereNull('read_at')
            ->whereIn('order_id', $assignments->pluck('order_id'))
            ->groupBy('order_id')->selectRaw('order_id, COUNT(*) AS total')->pluck('total', 'order_id');
        $assignments->each(fn (OrderAssignment $a) => $a->setAttribute('unread_messages', (int) ($unread[$a->order_id] ?? 0)));

        // Gain : attendu selon le plan pour une mission en cours, acquis pour une mission terminée
        if ($request->boolean('history')) {
            $earned = CourierEarning::where('courier_id', $courier->id)->whereIn('order_id', $assignments->pluck('order_id'))
                ->whereIn('type', [EarningType::Pickup, EarningType::Delivery, EarningType::FailedAttempt, EarningType::Return])
                ->get(['order_id', 'type', 'amount'])
                ->groupBy(fn (CourierEarning $e) => $e->order_id.'-'.match ($e->type) {
                    EarningType::Pickup => AssignmentType::Pickup->value,
                    EarningType::Return => AssignmentType::Return->value,
                    default => AssignmentType::Delivery->value,
                });
            $assignments->each(fn (OrderAssignment $a) => $a->setAttribute('gain', $earned->get($a->order_id.'-'.$a->type->value)?->sum('amount') ?: null));
        } else {
            $assignments->each(fn (OrderAssignment $a) => $a->setAttribute('gain', $pay->expectedFor($courier, $a->type, $a->order)));
        }

        // Dernière modification de la course depuis la mission, ce qui concerne le livreur (vue ou non)
        if (! $request->boolean('history')) {
            $edits = OrderEvent::query()->whereIn('order_id', $assignments->pluck('order_id'))->where('type', OrderEventType::Edited->value)
                ->latest('id')->get()->groupBy('order_id');
            $assignments->each(function (OrderAssignment $a) use ($edits) {
                $event = $edits->get($a->order_id)?->first(fn (OrderEvent $e) => $e->created_at >= $a->assigned_at
                    && OrderChanges::describe($e->meta['changes'] ?? [], OrderChanges::COURIER_FIELDS) !== []);
                $a->setAttribute('last_edit', $event ? [
                    'at' => $event->created_at->toIso8601String(),
                    'by' => $event->actor_role === 'merchant_owner' || $event->actor_role === 'merchant_staff' ? 'l\'e-commerçant' : 'l\'agence',
                    'changes' => OrderChanges::describe($event->meta['changes'] ?? [], OrderChanges::COURIER_FIELDS),
                    'seen' => $a->edit_seen_at !== null && $a->edit_seen_at >= $event->created_at,
                ] : null);
            });
        }

        // Livraison dont le colis est encore chez un autre livreur : relais à faire avant « Je pars livrer »
        $company = $request->user()->company;
        $holders = Courier::query()->with('user:id,name,phone')
            ->whereIn('id', $assignments->filter(fn (OrderAssignment $a) => $a->type === AssignmentType::Delivery && $a->order
                && ParcelRelays::needed($a->order, $company))->pluck('order.held_by_courier_id')->unique())
            ->get()->keyBy('id');
        $assignments->each(function (OrderAssignment $a) use ($holders, $company) {
            $holder = $a->type === AssignmentType::Delivery && $a->order && ParcelRelays::needed($a->order, $company) ? $holders->get($a->order->held_by_courier_id) : null;
            if ($holder) {
                $a->setAttribute('relay_from', [
                    'courier_id' => $holder->id, 'name' => $holder->user?->name, 'phone' => $holder->user?->phone,
                    'lat' => $holder->current_lat, 'lng' => $holder->current_lng, 'located_at' => $holder->last_location_at,
                ]);
            }
        });

        // Mission acceptée pas encore commencée : le livreur peut la rendre (empêchement), sauf missions d'office sans refus
        $canReport = $courier->canReportImpediment();
        $assignments->each(fn (OrderAssignment $a) => $a->setAttribute('can_report_impediment', $canReport && $a->status === AssignmentStatus::Accepted));

        return OrderAssignmentResource::collection($assignments)->additional(['meta' => [
            'acceptance' => $courier->acceptanceMode(),
            // Toutes consignes non lues (y compris sur des missions terminées)
            // Colis à remettre ou à recevoir en relais
            'relays' => app(ParcelRelays::class)->count($courier),
            'unread_messages' => CourierMessage::where('courier_id', $courier->id)->whereNull('read_at')->where('created_at', '>=', now()->subDays(2))->count(),
        ]]);
    }

    /** « J'ai vu » : le livreur a lu la modification de sa mission (noté dans le journal pour l'équipe) */
    public function editSeen(Request $request, OrderAssignment $assignment, OrderJournal $journal): JsonResponse
    {
        abort_unless($assignment->courier_id === $request->user()->courier?->id, 403);
        $assignment->forceFill(['edit_seen_at' => now()])->save();
        $journal->record($assignment->order, $request->user(), OrderEventType::Note, [
            'note' => 'Le livreur a vu la modification de la course.',
            'visible_to_merchant' => false,
            'assignment_id' => $assignment->id,
        ]);

        return response()->json(['data' => ['seen' => true]]);
    }

    public function accept(Request $request, OrderAssignment $assignment, OrderDispatcher $dispatcher): OrderAssignmentResource
    {
        $assignment = $dispatcher->respond($request->user(), $assignment, true);

        return OrderAssignmentResource::make($assignment->load(['order' => fn ($q) => $q->with(OrderController::LIST_RELATIONS)]));
    }

    /**
     * Refus d'une mission, ou empêchement sur une mission acceptée pas encore commencée. Un ramassage du planning
     * passe au remplaçant, une livraison automatique à un autre livreur ; sinon la course revient à l'équipe, prévenue.
     */
    public function refuse(Request $request, OrderAssignment $assignment, OrderDispatcher $dispatcher, AutoAssigner $auto): OrderAssignmentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motif']);
        $impediment = $assignment->status === AssignmentStatus::Accepted;

        $assignment = $dispatcher->respond($request->user(), $assignment, false, $data['reason']);

        $after = ($impediment ? 'empêchement de ' : 'refus de ').$request->user()->name;
        match ($assignment->type) {
            AssignmentType::Pickup => $auto->pickup($assignment->order, $after),
            AssignmentType::Delivery => $auto->delivery($assignment->order, $after),
            default => null,
        };

        return OrderAssignmentResource::make($assignment);
    }

    public function updateStatus(Request $request, CourierTracker $tracker): CourierResource
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403, 'Profil livreur introuvable.');
        $courier->touchSeen();

        $data = $request->validate([
            'is_available' => ['sometimes', 'boolean'],
            'lat' => ['required_with:lng', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        if (array_key_exists('is_available', $data)) {
            $courier->is_available = $data['is_available'];
        }

        if (isset($data['lat'], $data['lng'])) {
            $courier->forceFill([
                'current_lat' => $data['lat'],
                'current_lng' => $data['lng'],
                'last_location_at' => now(),
            ]);
        }

        $changed = $courier->isDirty(['is_available', 'current_lat', 'current_lng', 'last_location_at']);
        $courier->save();

        // Historique des trajets, pendant le service uniquement
        if (isset($data['lat'], $data['lng']) && $courier->is_available) {
            $tracker->record($courier, (float) $data['lat'], (float) $data['lng'], $data['accuracy'] ?? null);
        }

        // Carte des livreurs en direct (personnel de l'entreprise)
        if ($changed) {
            CourierLocationUpdated::dispatch($courier);
        }

        return CourierResource::make($courier->load(['user', 'zones'])->loadCount('activeAssignments'));
    }
}
