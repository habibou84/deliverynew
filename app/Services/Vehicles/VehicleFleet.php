<?php

namespace App\Services\Vehicles;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Courier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleDocument;
use App\Notifications\VehicleDocumentAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Parc des véhicules : affectation à un livreur (avec l'historique), état des papiers, avertissement au dispatch
 * et rappels des papiers qui arrivent à leur fin.
 */
class VehicleFleet
{
    /**
     * Confie le véhicule à un livreur (ou le reprend : null). Un livreur n'a qu'un véhicule à la fois : l'ancien
     * lui est repris. Le type et la plaque du livreur suivent (choix des missions, plans de paie).
     */
    public function assign(Vehicle $vehicle, ?Courier $courier, ?User $by = null): Vehicle
    {
        if ($vehicle->courier_id === $courier?->id) {
            return $vehicle;
        }

        DB::transaction(function () use ($vehicle, $courier, $by) {
            if ($vehicle->courier_id !== null) {
                $this->release($vehicle);
            }
            if ($courier === null) {
                return;
            }
            Vehicle::query()->where('courier_id', $courier->id)->whereKeyNot($vehicle->id)->get()
                ->each(fn (Vehicle $other) => $this->release($other));

            $vehicle->update(['courier_id' => $courier->id]);
            VehicleAssignment::create([
                'company_id' => $vehicle->company_id,
                'vehicle_id' => $vehicle->id,
                'courier_id' => $courier->id,
                'started_at' => now(),
                'assigned_by' => $by?->id,
            ]);
            $courier->update(['vehicle_type' => $vehicle->type, 'vehicle_plate' => $vehicle->plate]);
        });

        return $vehicle->refresh();
    }

    /** Le livreur rend le véhicule : fin de la période en cours */
    private function release(Vehicle $vehicle): void
    {
        VehicleAssignment::query()->where('vehicle_id', $vehicle->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        Courier::query()->whereKey($vehicle->courier_id)->where('vehicle_plate', $vehicle->plate)->update(['vehicle_plate' => null]);
        $vehicle->update(['courier_id' => null]);
    }

    /** Une fiche modifiée : la plaque et le type du livreur suivent */
    public function synced(Vehicle $vehicle): void
    {
        if ($vehicle->courier_id !== null) {
            Courier::query()->whereKey($vehicle->courier_id)->update(['vehicle_type' => $vehicle->type, 'vehicle_plate' => $vehicle->plate]);
        }
    }

    /**
     * Papier en cours de chaque type : le plus récent (un papier renouvelé remplace l'ancien).
     *
     * @param  Collection<int, VehicleDocument>  $documents
     * @return Collection<string, VehicleDocument>
     */
    public static function current(Collection $documents): Collection
    {
        return $documents->sortByDesc(fn (VehicleDocument $d) => [$d->expires_at?->timestamp ?? 0, $d->id])
            ->unique('type')->keyBy('type');
    }

    /**
     * Ce qui empêche ou risque d'empêcher le livreur de rouler, pour le dispatch : véhicule en réparation ou retiré,
     * assurance ou permis expiré (rouge), assurance non renseignée ou bientôt finie (orange).
     *
     * @return list<array{level: string, text: string}>
     */
    public function warnings(Courier $courier): array
    {
        $vehicle = $courier->relationLoaded('vehicle') ? $courier->vehicle : $courier->vehicle()->with('documents')->first();
        $warnings = [];

        if ($vehicle !== null) {
            if (! $vehicle->isActive()) {
                $warnings[] = ['level' => 'alert', 'text' => $vehicle->status === 'repair' ? 'Véhicule en réparation' : 'Véhicule retiré du parc'];
            }
            $insurance = self::current($vehicle->documents)->get('insurance');
            $warnings[] = match ($insurance?->state()) {
                null => ['level' => 'warn', 'text' => 'Assurance à renseigner'],
                'expired' => ['level' => 'alert', 'text' => 'Assurance expirée'],
                'soon' => ['level' => 'warn', 'text' => 'Assurance : fin le '.$insurance->expires_at->format('d/m')],
                default => null,
            };
        }

        $documents = $courier->relationLoaded('documents') ? $courier->documents : $courier->documents()->get();
        $license = self::current($documents)->get('license');
        if ($license?->state() === 'expired') {
            $warnings[] = ['level' => 'alert', 'text' => 'Permis expiré'];
        }

        return array_values(array_filter($warnings));
    }

    /**
     * Rappels des papiers : 15 jours avant la fin, puis le jour de la fin. Un papier déjà renouvelé (un autre du
     * même type, plus récent) n'est pas rappelé. Renvoie le nombre de rappels envoyés.
     */
    public function remind(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;

        VehicleDocument::withoutGlobalScopes()->with(['vehicle', 'courier.user'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->addDays(VehicleDocument::WARN_DAYS)->toDateString())
            ->whereNull('expired_warned_at')
            ->orderBy('id')
            ->each(function (VehicleDocument $document) use ($today, &$sent) {
                if (! $this->stillInUse($document) || $this->renewed($document)) {
                    return;
                }
                $ended = $document->expires_at->lte($today);
                if (! $ended && $document->warned_at !== null) {
                    return;
                }

                $users = User::forCompany($document->company_id)->where('status', 'active')
                    ->role([Role::Admin->value, Role::Dispatcher->value])->get();
                if ($users->isNotEmpty()) {
                    Notification::send($users, new VehicleDocumentAlert($document, $ended ? 'expired' : 'soon'));
                    $sent++;
                }
                $document->forceFill($ended
                    ? ['expired_warned_at' => now(), 'warned_at' => $document->warned_at ?? now()]
                    : ['warned_at' => now()])->save();
            });

        return $sent;
    }

    /** Véhicule encore dans le parc, ou livreur encore actif */
    private function stillInUse(VehicleDocument $document): bool
    {
        if ($document->vehicle_id !== null) {
            return $document->vehicle !== null && $document->vehicle->status !== 'retired';
        }

        return $document->courier?->user?->status === UserStatus::Active;
    }

    private function renewed(VehicleDocument $document): bool
    {
        return VehicleDocument::withoutGlobalScopes()
            ->where('type', $document->type)
            ->where($document->vehicle_id !== null ? 'vehicle_id' : 'courier_id', $document->vehicle_id ?? $document->courier_id)
            ->whereKeyNot($document->id)
            ->where('expires_at', '>', $document->expires_at->toDateString())
            ->exists();
    }
}
