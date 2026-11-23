<?php

namespace App\Services\Couriers;

use App\Enums\OrderEventType;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\ParcelDrop;
use App\Models\ParcelGap;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderJournal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Dépôt en libre-service : le livreur rend ses colis au dépôt sans agent.
 *  1. il scanne le QR code de la borne du dépôt (il change toutes les 30 secondes : il faut être devant) ;
 *  2. il scanne l'étiquette de chaque colis en le posant dans le bac « Retours livreurs » et prend une photo ;
 *  3. les colis sont « déposés, à contrôler » : plus à rendre, mais pas encore confirmés au dépôt ;
 *  4. un agent contrôle le bac plus tard : colis trouvés confirmés au dépôt, colis introuvables avec un motif
 *     (écart de remise, le livreur confirme ou conteste) ; sans contrôle, confirmation automatique au bout de 24 h.
 */
class ParcelDrops
{
    /** Durée d'un QR code de la borne, en secondes */
    public const WINDOW = 30;

    public function __construct(
        private readonly ParcelCustody $custody,
        private readonly ParcelGaps $gaps,
        private readonly OrderJournal $journal,
    ) {}

    /** Code affiché par la borne du dépôt pour la fenêtre de temps en cours */
    public static function kioskCode(int $companyId, ?int $window = null): string
    {
        $window ??= intdiv(time(), self::WINDOW);

        return "JIBIAT-DEPOT:{$companyId}:{$window}:".self::signature($companyId, $window);
    }

    /** Code de la borne valable (fenêtre en cours ou les deux précédentes : 30 à 90 secondes) */
    public static function validKiosk(int $companyId, string $code): bool
    {
        $parts = explode(':', trim($code));
        if (count($parts) !== 4 || $parts[0] !== 'JIBIAT-DEPOT' || (int) $parts[1] !== $companyId) {
            return false;
        }
        $window = (int) $parts[2];
        $now = intdiv(time(), self::WINDOW);

        return $window <= $now && $window >= $now - 2 && hash_equals(self::signature($companyId, $window), $parts[3]);
    }

    /** Durée d'un dépôt commencé devant la borne (scan des colis, photo), en minutes */
    public const SESSION_MINUTES = 30;

    /**
     * Le livreur scanne la borne : elle doit être valable maintenant. Il reçoit un jeton de dépôt pour scanner ses
     * colis et prendre la photo dans les 30 minutes (le QR code de la borne, lui, change toutes les 30 secondes).
     */
    public function start(User $courierUser, string $kioskCode): string
    {
        $courier = $courierUser->courier;
        abort_if($courier === null, 403);

        if (! self::validKiosk($courier->company_id, $kioskCode)) {
            throw new BusinessRuleException('QR code de la borne expiré ou invalide : scannez à nouveau la borne du dépôt.', 'kiosk_code');
        }

        $at = time();

        return "{$courier->id}.{$at}.".substr(hash_hmac('sha256', "drop:{$courier->id}:{$at}", (string) config('app.key')), 0, 24);
    }

    private static function validSession(int $courierId, string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || (int) $parts[0] !== $courierId) {
            return false;
        }
        $at = (int) $parts[1];

        return $at <= time() && $at >= time() - self::SESSION_MINUTES * 60
            && hash_equals(substr(hash_hmac('sha256', "drop:{$courierId}:{$at}", (string) config('app.key')), 0, 24), $parts[2]);
    }

    private static function signature(int $companyId, int $window): string
    {
        return substr(hash_hmac('sha256', "depot:{$companyId}:{$window}", (string) config('app.key')), 0, 16);
    }

    /**
     * Le livreur dépose des colis dans le bac du dépôt (jeton obtenu en scannant la borne).
     *
     * @param  list<int>  $orderIds
     * @param  list<string>  $typedCodes  codes tapés à la main (étiquette illisible)
     */
    public function drop(User $courierUser, string $session, array $orderIds, array $typedCodes, UploadedFile $photo, ?float $lat = null, ?float $lng = null): ParcelDrop
    {
        $courier = $courierUser->courier;
        abort_if($courier === null, 403);

        if (! self::validSession($courier->id, $session)) {
            throw new BusinessRuleException('Dépôt expiré : scannez à nouveau la borne du dépôt.', 'session');
        }

        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        return DB::transaction(function () use ($courier, $courierUser, $orderIds, $typedCodes, $photo, $lat, $lng) {
            $orders = Order::query()->whereIn('id', $orderIds)->where('held_by_courier_id', $courier->id)->lockForUpdate()->get();
            $notDue = $orders->reject(fn (Order $o) => ParcelCustody::isDue($o));

            if ($orders->count() !== count($orderIds) || $notDue->isNotEmpty()) {
                throw new BusinessRuleException('Certains colis ne sont pas (ou plus) à rendre au dépôt'
                    .($notDue->isNotEmpty() ? ' : '.$notDue->pluck('tracking_code')->implode(', ') : '').'.', 'order_ids');
            }

            $drop = ParcelDrop::create([
                'company_id' => $courier->company_id,
                'courier_id' => $courier->id,
                'status' => ParcelDrop::PENDING,
                'order_ids' => $orders->modelKeys(),
                'typed_codes' => array_values(array_intersect(array_map('strtoupper', $typedCodes), $orders->pluck('tracking_code')->all())) ?: null,
                'lat' => $lat,
                'lng' => $lng,
                'dropped_at' => now(),
            ]);
            $drop->update(['photo_path' => $photo->store("parcel-drops/{$courier->company_id}/{$drop->id}", 'local')]);

            foreach ($orders as $order) {
                $order->forceFill(['parcel_drop_id' => $drop->id])->save();
                $this->journal->record($order, $courierUser, OrderEventType::Note, [
                    'note' => 'Déposé dans le bac du dépôt par '.($courierUser->name).' : en attente du contrôle.',
                    'visible_to_merchant' => false,
                ]);
            }

            return $drop;
        });
    }

    /**
     * Contrôle du bac : colis trouvés confirmés au dépôt, colis introuvables avec un motif (écart de remise).
     *
     * @param  list<int>  $foundIds
     * @param  array<int, array{reason: string, note?: ?string}>  $missing
     */
    public function control(User $actor, ParcelDrop $drop, array $foundIds, array $missing): ParcelDrop
    {
        if ($drop->status !== ParcelDrop::PENDING) {
            throw new BusinessRuleException('Ce dépôt est déjà contrôlé.', 'status');
        }

        $foundIds = array_map('intval', $foundIds);
        $courier = $drop->courier()->with('user')->first();

        [$found, $gaps] = DB::transaction(function () use ($actor, $drop, $foundIds, $missing, $courier) {
            $orders = Order::query()->where('parcel_drop_id', $drop->id)->lockForUpdate()->orderBy('id')->get();
            $without = $orders->filter(fn (Order $o) => ! in_array($o->id, $foundIds, true)
                && (! isset(ParcelGap::REASONS[$missing[$o->id]['reason'] ?? '']) || (($missing[$o->id]['reason'] ?? '') === 'other' && blank($missing[$o->id]['note'] ?? null))));

            if ($without->isNotEmpty()) {
                throw new BusinessRuleException('Indiquez pourquoi ces colis ne sont pas dans le bac : '.$without->pluck('tracking_code')->implode(', ').'.', 'missing');
            }

            $name = $courier?->user?->name ?? 'le livreur';
            $found = collect();
            $gaps = collect();
            foreach ($orders as $order) {
                if (in_array($order->id, $foundIds, true)) {
                    $found->push($this->custody->toHub($actor, $order, "Dépôt contrôlé : colis trouvé dans le bac (déposé par {$name})"));

                    continue;
                }
                // Introuvable : il reste noté chez le livreur, qui confirme ou conteste
                $order->forceFill(['parcel_drop_id' => null])->save();
                $gaps->push(ParcelGap::create([
                    'company_id' => $drop->company_id,
                    'courier_id' => $drop->courier_id,
                    'order_id' => $order->id,
                    'source' => ParcelGap::DROP_CONTROL,
                    'parcel_drop_id' => $drop->id,
                    'reported_by' => $actor->id,
                    'reason' => $missing[$order->id]['reason'],
                    'note' => filled($missing[$order->id]['note'] ?? null) ? mb_substr(trim($missing[$order->id]['note']), 0, 500) : null,
                ])->setRelation('order', $order));
            }

            $drop->forceFill(['status' => ParcelDrop::CONTROLLED, 'controlled_at' => now(), 'controlled_by' => $actor->id])->save();

            return [$found, $gaps];
        });

        if ($courier !== null) {
            $this->gaps->notifyReceipt($actor, $courier, $found, $gaps, 'Contrôle de votre dépôt');
        }

        return $drop->refresh();
    }

    /**
     * Dépôts restés sans contrôle 24 h : colis confirmés au dépôt, les administrateurs sont prévenus.
     *
     * @return int dépôts confirmés
     */
    public function autoConfirm(): int
    {
        $count = 0;
        $drops = ParcelDrop::query()->withoutGlobalScopes()->where('status', ParcelDrop::PENDING)
            ->where('dropped_at', '<=', now()->subHours(ParcelDrop::AUTO_CONFIRM_HOURS))->orderBy('id')->get();

        foreach ($drops->groupBy('company_id') as $companyId => $list) {
            $admins = User::forCompany($companyId)->where('status', 'active')->role(Role::Admin->value)->get();
            $actor = $admins->first();
            if ($actor === null) {
                continue;
            }

            $parcels = 0;
            foreach ($list as $drop) {
                DB::transaction(function () use ($actor, $drop, &$parcels) {
                    $orders = Order::query()->withoutGlobalScopes()->where('parcel_drop_id', $drop->id)->lockForUpdate()->get();
                    foreach ($orders as $order) {
                        $this->custody->toHub($actor, $order, 'Dépôt confirmé automatiquement : bac non contrôlé depuis '.ParcelDrop::AUTO_CONFIRM_HOURS.' h');
                        $parcels++;
                    }
                    $drop->forceFill(['status' => ParcelDrop::AUTO, 'controlled_at' => now()])->save();
                });
                $count++;
            }

            $first = Order::query()->withoutGlobalScopes()->whereIn('id', $list->first()->order_ids)->first();
            if ($first !== null) {
                Notification::send($admins, new OrderAlert($first, 'parcel_drops_auto',
                    '🕒 Dépôts confirmés sans contrôle',
                    $list->count().' dépôt'.($list->count() > 1 ? 's' : '')." ({$parcels} colis) confirmé".($list->count() > 1 ? 's' : '').' automatiquement : le bac n\'a pas été contrôlé en '.ParcelDrop::AUTO_CONFIRM_HOURS.' h.',
                    ['href' => '/admin/colis-livreurs?vue=depots']));
            }
        }

        return $count;
    }

    /**
     * Données d'un dépôt pour l'écran de contrôle.
     *
     * @return array<string, mixed>
     */
    public static function data(ParcelDrop $drop, ?Collection $orders = null): array
    {
        $orders ??= Order::query()->whereIn('id', $drop->order_ids ?? [])->with('merchant:id,business_name')->orderBy('id')->get();

        return [
            'id' => $drop->id,
            'status' => $drop->status,
            'courier' => ['id' => $drop->courier_id, 'name' => $drop->courier?->user?->name],
            'dropped_at' => $drop->dropped_at,
            'auto_confirm_at' => $drop->dropped_at?->copy()->addHours(ParcelDrop::AUTO_CONFIRM_HOURS),
            'controlled_at' => $drop->controlled_at,
            'controlled_by' => $drop->controller?->name,
            'has_photo' => $drop->photo_path !== null,
            'typed_codes' => $drop->typed_codes ?? [],
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id,
                'tracking_code' => $o->tracking_code,
                'recipient_name' => $o->recipient_name,
                'merchant' => $o->merchant?->business_name,
                'status_label' => $o->status->label(),
                // Encore à contrôler dans ce dépôt
                'pending' => $o->parcel_drop_id === $drop->id,
            ])->values(),
        ];
    }
}
