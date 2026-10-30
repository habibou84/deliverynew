<?php

namespace App\Services\Legacy;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\FeePayer;
use App\Enums\LedgerEntryType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Recipient;
use App\Models\Zone;
use App\Services\Orders\ZoneResolver;
use App\Support\Money;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Commandes de l'ancienne application (table « commands » et ses tables liées) reprises en courses, sans aucun
 * message ni alerte : ni WhatsApp au client, ni notification, ni webhook, ni gain de livreur.
 *
 * - Montants : articles = montant − remise (ce que touche l'e-commerçant), livraison = colonne « livraison » à la
 *   charge du client ; prix gardés tels quels (pas recalculés avec les tarifs).
 * - États : en cours, récupéré, en chemin, termine, annule, retour (voir status()).
 * - Argent : paiement « termine » = déjà reversé (course soldée) ; « encours » = encore dû à l'e-commerçant (son
 *   montant fait foi). Argent encore chez le livreur tant que « cashedback_at » est vide.
 * - Avant la bascule, l'ancienne application fait foi ; après, une course déjà là n'est plus écrasée (signalée).
 */
class LegacyOrdersImporter
{
    public const SOURCE = 'legacy';

    /** Zone des courses dont la commune n'a pas été reconnue */
    public const UNKNOWN_ZONE = 'À préciser';

    private Company $company;

    private Collection $zones;

    /** @var array<string, Merchant|null> */
    private array $merchants = [];

    /** @var array<string, Courier|null> */
    private array $couriers = [];

    private ?Zone $unknownZone = null;

    /** @var array<int, MerchantPayout> reversements « ancienne application » du jour, par e-commerçant */
    private array $payouts = [];

    public function __construct(private readonly ZoneResolver $zoneResolver) {}

    public function for(Company $company): self
    {
        $this->company = $company;
        $this->zones = Zone::forCompany($company->id)->with('parent')->get();
        $this->merchants = [];
        $this->couriers = [];
        $this->payouts = [];
        $this->unknownZone = $this->zones->first(fn (Zone $z) => $z->name === self::UNKNOWN_ZONE);

        return $this;
    }

    /**
     * Reprend une commande : création, mise à jour (l'ancienne application fait foi avant la bascule), ou rien si
     * elle n'a pas changé.
     *
     * @param  array<string, mixed>  $c  commande envoyée par le module de l'ancienne application
     * @return array{action: string, status: ?string, warnings: list<string>, reason?: string, owed: int, courier_cash: int, merchant_legacy_id: ?string, courier_legacy_id: ?string}
     */
    public function import(array $c, bool $dryRun = false, bool $live = false): array
    {
        $id = (string) ($c['id'] ?? '');
        $warnings = [];
        $base = ['status' => null, 'warnings' => [], 'owed' => 0, 'courier_cash' => 0, 'merchant_legacy_id' => (string) ($c['client_id'] ?? ''), 'courier_legacy_id' => (string) ($c['livreur_id'] ?? '') ?: null];

        $state = self::state($c['etat'] ?? null);
        if ($state === null) {
            return [...$base, 'action' => 'skipped', 'reason' => 'état inconnu : « '.($c['etat'] ?? '').' »'];
        }

        $merchant = $this->merchant($c['client'] ?? null, $c['client_id'] ?? null);
        if ($merchant === null) {
            return [...$base, 'action' => 'skipped', 'reason' => 'e-commerçant non repris (client n° '.($c['client_id'] ?? '?').')'];
        }

        $courier = $this->courier($c['livreur'] ?? null, $c['livreur_id'] ?? null);
        if (! empty($c['livreur_id']) && $courier === null) {
            $warnings[] = 'livreur non repris (n° '.$c['livreur_id'].')';
        }

        if ($this->zone($c['commune'] ?? null) === null) {
            $warnings[] = 'commune « '.($this->text($c['commune'] ?? null) ?? '—').' » inconnue : zone « '.self::UNKNOWN_ZONE.' »';
        }

        $plan = $this->plan($c, $state, $courier, $warnings);
        $money = $this->money($c, $plan, $warnings);
        $hash = hash('sha256', json_encode($c));
        $existing = Order::withoutGlobalScopes()->withTrashed()->where('company_id', $this->company->id)->where('legacy_id', $id)->first();

        $result = [...$base, 'status' => $plan['status']->value, 'warnings' => $warnings, 'owed' => $money['owed'], 'courier_cash' => $money['courier_cash']];

        // Inchangée, ou clôturée par le nettoyage des anciennes courses : plus recopiée
        if ($existing && ($existing->legacy_hash === $hash || $existing->legacy_purged_at !== null)) {
            return [...$result, 'action' => 'unchanged'];
        }

        // Après la bascule, Jibiat fait foi : une course déjà là n'est plus écrasée par l'ancienne application
        if ($existing && $this->switched()) {
            if (! $dryRun && $existing->legacy_conflict_at === null) {
                $existing->forceFill(['legacy_conflict_at' => now(), 'legacy_hash' => $hash])->save();
                $this->event($existing, OrderEventType::Note, 'conflict:'.substr($hash, 0, 12), now(), [
                    'note' => '⚠️ Commande modifiée dans l\'ancienne application après la bascule : rien n\'a été changé ici, vérifiez-la.',
                ]);
            }

            return [...$result, 'action' => 'conflict'];
        }

        if ($dryRun) {
            return [...$result, 'action' => $existing ? 'updated' : 'created'];
        }

        try {
            DB::transaction(function () use ($c, $plan, $money, $merchant, $courier, $hash, $existing) {
                $order = $this->save($c, $plan, $money, $merchant, $hash, $existing);
                $this->assignments($order, $plan, $courier);
                $this->items($order, $c['items'] ?? []);
                $this->history($order, $c, $plan);
                $this->finance($order, $plan, $money, $courier);
            });
        } catch (Throwable $e) {
            report($e);

            return [...$result, 'action' => 'skipped', 'reason' => 'erreur : '.Str::limit($e->getMessage(), 160)];
        }

        return [...$result, 'action' => $existing ? 'updated' : 'created'];
    }

    /** Ancien état, sans accents ni majuscules : en_cours, recupere, en_chemin, termine, annule, retour */
    public static function state(?string $etat): ?string
    {
        $etat = (string) $etat;
        // Accents enregistrés deux fois (« rÃ©cupÃ©rÃ© ») : remis d'aplomb
        if (str_contains($etat, 'Ã')) {
            $etat = mb_convert_encoding($etat, 'ISO-8859-1', 'UTF-8');
        }
        $key = trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($etat))));

        return match ($key) {
            'en cours', 'encours' => 'en_cours',
            'recupere', 'recuperee' => 'recupere',
            'en chemin' => 'en_chemin',
            'termine', 'terminee' => 'termine',
            'annule', 'annulee' => 'annule',
            'retour', 'retourne', 'retournee' => 'retour',
            default => null,
        };
    }

    /**
     * Statut dans Jibiat, livreur qui a le colis, missions à créer.
     *
     * @param  list<string>  $warnings
     * @return array{status: OrderStatus, pickup: ?AssignmentStatus, delivery: ?AssignmentStatus, holder: bool, attempts: int, scheduled: ?string, failed_note: ?string, return_requested: bool}
     */
    private function plan(array $c, string $state, ?Courier $courier, array &$warnings): array
    {
        $relance = $this->day($c['relance_date'] ?? null);
        $undelivered = $this->text($c['why_undelivered'] ?? null);
        $plan = ['pickup' => null, 'delivery' => null, 'holder' => false, 'attempts' => 0, 'scheduled' => $this->day($c['delivery_date'] ?? null) ?? $relance, 'failed_note' => null, 'return_requested' => false];

        switch ($state) {
            case 'en_cours':
                return [...$plan, 'status' => $courier ? OrderStatus::PickupAssigned : OrderStatus::Confirmed, 'pickup' => $courier ? AssignmentStatus::Accepted : null];

            case 'recupere':
                if ($courier === null) {
                    $warnings[] = 'colis récupéré sans livreur : mis au dépôt';

                    return [...$plan, 'status' => OrderStatus::AtHub];
                }
                $plan = [...$plan, 'pickup' => AssignmentStatus::Completed, 'delivery' => AssignmentStatus::Accepted, 'holder' => true];
                if ($relance !== null && $relance >= today()->toDateString()) {
                    return [...$plan, 'status' => OrderStatus::Rescheduled, 'scheduled' => $relance, 'attempts' => $undelivered ? 1 : 0, 'failed_note' => $undelivered];
                }
                if ($undelivered !== null) {
                    return [...$plan, 'status' => OrderStatus::DeliveryFailed, 'attempts' => 1, 'failed_note' => $undelivered];
                }

                return [...$plan, 'status' => OrderStatus::PickedUp];

            case 'en_chemin':
                if ($courier === null) {
                    $warnings[] = 'en chemin sans livreur : mis au dépôt';

                    return [...$plan, 'status' => OrderStatus::AtHub];
                }

                return [...$plan, 'status' => OrderStatus::OutForDelivery, 'pickup' => AssignmentStatus::Completed, 'delivery' => AssignmentStatus::InProgress, 'holder' => true];

            case 'termine':
                return [...$plan, 'status' => OrderStatus::Delivered, 'pickup' => $courier ? AssignmentStatus::Completed : null, 'delivery' => $courier ? AssignmentStatus::Completed : null];

            case 'annule':
                return [...$plan, 'status' => OrderStatus::Cancelled];

            default: // retour
                if ($this->date($c['retour_at'] ?? null) !== null) {
                    return [...$plan, 'status' => OrderStatus::Returned, 'pickup' => $courier ? AssignmentStatus::Completed : null];
                }

                // Pas encore rendu : échec, colis à rendre (chez le livreur s'il y en a un)
                return [...$plan, 'status' => OrderStatus::DeliveryFailed, 'pickup' => $courier ? AssignmentStatus::Completed : null,
                    'holder' => $courier !== null, 'attempts' => 1, 'failed_note' => $undelivered ?? 'Retour demandé', 'return_requested' => true];
        }
    }

    /**
     * Montants : articles = montant − remise ; livraison payée par le client ; dû à l'e-commerçant = son paiement
     * « encours » (s'il existe) ; argent encore chez le livreur tant que la remise à la caisse n'est pas faite.
     *
     * @param  list<string>  $warnings
     * @return array{items: int, fee: int, cod: int, owed: int, paid: bool, courier_cash: int, payment: ?array}
     */
    private function money(array $c, array $plan, array &$warnings): array
    {
        $items = max(0, (int) ($c['montant'] ?? 0) - (int) ($c['remise'] ?? 0));
        $fee = max(0, (int) ($c['livraison'] ?? 0));
        $payment = is_array($c['payment'] ?? null) ? $c['payment'] : null;
        $delivered = $plan['status'] === OrderStatus::Delivered;
        $paid = $payment !== null && self::state($payment['etat'] ?? null) === 'termine';
        $owed = 0;

        if ($delivered) {
            if ($payment === null) {
                $warnings[] = 'livrée sans paiement enregistré : '.Money::format($items).' compté comme dû à l\'e-commerçant';
                $owed = $items;
            } elseif (! $paid) {
                $owed = (int) ($payment['montant'] ?? 0);
                if ($owed !== $items) {
                    $warnings[] = 'paiement de '.Money::format($owed).' au lieu de '.Money::format($items).' (montant − remise)';
                }
            }
        }

        $cashBack = $this->date($c['cashedback_at'] ?? null) !== null;

        return [
            'items' => $items,
            'fee' => $fee,
            'cod' => $items + $fee,
            'owed' => $owed,
            'paid' => $paid,
            'courier_cash' => $delivered && ! $cashBack && ! empty($c['livreur_id']) ? $items + $fee : 0,
            'payment' => $payment,
        ];
    }

    private function save(array $c, array $plan, array $money, Merchant $merchant, string $hash, ?Order $order): Order
    {
        $created = $this->date($c['created_at'] ?? null) ?? now();
        $updated = $this->date($c['updated_at'] ?? null) ?? $created;
        $phone = PhoneNumber::normalize((string) ($c['phone'] ?? '')) ?? Str::limit(trim((string) ($c['phone'] ?? '')), 20, '');
        [$lat, $lng] = $this->position($c['latitude'] ?? null, $c['longitude'] ?? null);
        $address = $this->text($c['adresse'] ?? null);
        $place = $this->text($c['lieu'] ?? null);
        $pickupZone = $this->zone($c['ram_commune'] ?? null) ?? $merchant->pickupZone ?? $this->unknownZone();
        $status = $plan['status'];

        $order ??= new Order;
        $order->forceFill([
            'company_id' => $this->company->id,
            'merchant_id' => $merchant->id,
            'legacy_id' => (string) $c['id'],
            'source' => self::SOURCE,
            'merchant_alerts_muted' => true,
            'pickup_zone_id' => $pickupZone->id,
            'pickup_address' => $this->limit($c['ram_adresse'] ?? null, 255) ?? $merchant->pickup_address,
            'pickup_contact_name' => $this->limit($c['ram_name'] ?? null, 255) ?? $merchant->contact_name ?? $merchant->business_name,
            'pickup_phone' => PhoneNumber::normalize((string) ($c['ram_phone'] ?? '')) ?? $merchant->phone,
            'pickup_lat' => $merchant->pickup_lat,
            'pickup_lng' => $merchant->pickup_lng,
            'recipient_name' => $this->limit($c['nom_client'] ?? null, 255) ?? $this->limit($c['client_name'] ?? null, 255),
            'recipient_phone' => $phone,
            'recipient_phone2' => PhoneNumber::normalize((string) ($c['phone2'] ?? '')),
            'delivery_zone_id' => ($this->zone($c['commune'] ?? null) ?? $this->unknownZone())->id,
            'delivery_address' => $this->limit($address ?? $place, 255),
            'delivery_landmark' => $address !== null ? $this->limit($place, 255) : null,
            'delivery_lat' => $lat,
            'delivery_lng' => $lng,
            'delivery_scheduled_date' => $plan['scheduled'],
            'description' => $this->limit($c['description'] ?? null, 2000),
            'is_express' => (bool) ($c['is_express'] ?? false),
            'merchant_note' => $this->limit($c['observation'] ?? null, 1000),
            'delivery_fee' => $money['fee'],
            'surcharges_total' => 0,
            'pricing_details' => ['legacy' => true, 'remise' => (int) ($c['remise'] ?? 0)],
            'fee_payer' => FeePayer::Recipient,
            'items_amount' => $money['items'],
            'cod_amount' => $money['cod'],
            'max_attempts' => max($order->max_attempts ?? 0, $this->company->default_max_attempts ?? 3, $plan['attempts'] + 1),
            'status' => $status,
            'attempts_count' => $plan['attempts'],
            'return_requested' => $plan['return_requested'],
            'held_by_courier_id' => null,
            'confirmed_at' => $created,
            'picked_up_at' => in_array($status, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::Cancelled], true) ? null : $updated,
            'delivered_at' => $status === OrderStatus::Delivered ? $updated : null,
            'collected_amount' => $status === OrderStatus::Delivered ? $money['cod'] : null,
            'returned_at' => $status === OrderStatus::Returned ? ($this->date($c['retour_at'] ?? null) ?? $updated) : null,
            'cancelled_at' => $status === OrderStatus::Cancelled ? $updated : null,
            'legacy_hash' => $hash,
            'legacy_synced_at' => now(),
        ]);
        if (! $order->exists) {
            $order->created_at = $created;
        }
        $order->recipient_id = $order->recipient_phone ? $this->recipient($merchant, $order)?->id : null;
        $order->save();
        // Heure d'entrée dans le statut : celle de l'ancienne application (pas d'alerte « sans livreur » sur l'historique)
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['status_changed_at' => $updated]);

        return $order;
    }

    /** Missions du livreur selon l'état : remplacées si elles ne correspondent plus */
    private function assignments(Order $order, array $plan, ?Courier $courier): void
    {
        $wanted = array_filter([
            AssignmentType::Pickup->value => $plan['pickup'],
            AssignmentType::Delivery->value => $plan['delivery'],
        ]);
        $current = OrderAssignment::query()->where('order_id', $order->id)->whereNotIn('status', [AssignmentStatus::Cancelled->value, AssignmentStatus::Refused->value])->get();

        foreach ($current as $assignment) {
            $status = $wanted[$assignment->type->value] ?? null;
            if ($status === null || $assignment->courier_id !== $courier?->id) {
                $assignment->forceFill(['status' => AssignmentStatus::Cancelled, 'completed_at' => now()])->save();

                continue;
            }
            $assignment->forceFill(['status' => $status] + $this->assignmentTimes($status, $order))->save();
            unset($wanted[$assignment->type->value]);
        }

        foreach ($wanted as $type => $status) {
            OrderAssignment::create([
                'order_id' => $order->id,
                'courier_id' => $courier->id,
                'type' => $type,
                'status' => $status,
                'assigned_at' => $order->created_at,
                'accepted_at' => $order->created_at,
            ] + $this->assignmentTimes($status, $order));
        }

        $order->forceFill([
            'pickup_courier_id' => $plan['pickup'] ? $courier?->id : null,
            'delivery_courier_id' => $plan['delivery'] || ($plan['status'] === OrderStatus::Delivered) ? $courier?->id : null,
            'held_by_courier_id' => $plan['holder'] ? $courier?->id : null,
            'held_since' => $plan['holder'] ? ($order->held_since ?? $order->picked_up_at ?? now()) : null,
        ])->save();
    }

    private function assignmentTimes(AssignmentStatus $status, Order $order): array
    {
        return match ($status) {
            AssignmentStatus::InProgress => ['started_at' => $order->picked_up_at ?? now()],
            AssignmentStatus::Completed => ['started_at' => $order->picked_up_at, 'completed_at' => $order->delivered_at ?? $order->picked_up_at ?? now()],
            default => [],
        };
    }

    /** Articles de la commande (repris une fois ; produit relié s'il a été repris) */
    private function items(Order $order, array $items): void
    {
        if ($items === [] || OrderItem::query()->where('order_id', $order->id)->exists()) {
            return;
        }

        $products = Product::forCompany($this->company->id)->whereIn('legacy_id', array_map(fn ($i) => (string) ($i['product_id'] ?? ''), $items))
            ->pluck('id', 'legacy_id');
        foreach ($items as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $products[(string) ($item['product_id'] ?? '')] ?? null,
                'label' => $this->limit($item['product_name'] ?? null, 255) ?? 'Article',
                'quantity' => max(1, (int) ($item['qty'] ?? 1)),
                'unit_price' => max(0, (int) ($item['price'] ?? 0)),
            ]);
        }
    }

    /**
     * Historique de la course, sans doublon d'une copie à l'autre (clé « legacy » de chaque ligne) : reprise, notes,
     * historique de l'ancienne application, relances, frais, motif de non-livraison, état actuel.
     */
    private function history(Order $order, array $c, array $plan): void
    {
        $created = $this->date($c['created_at'] ?? null) ?? now();
        $this->event($order, OrderEventType::Created, 'created', $created, [
            'note' => 'Reprise de l\'ancienne application (commande n° '.$c['id'].')',
            'to_status' => OrderStatus::Confirmed,
        ]);

        foreach ($c['notes'] ?? [] as $n) {
            $text = $this->text($n['description'] ?? null);
            if ($text !== null) {
                $rdv = $this->text($n['rdv_time'] ?? null);
                $this->event($order, OrderEventType::Note, 'note:'.$n['id'], $this->date($n['created_at'] ?? null) ?? $created, [
                    'note' => Str::limit($text.($rdv ? " (rendez-vous {$rdv})" : ''), 1000),
                    'actor_name' => $this->text($n['user_type'] ?? null) ?? 'Ancienne application',
                ]);
            }
        }

        foreach ($c['events'] ?? [] as $e) {
            $text = $this->text($e['description'] ?? null);
            if ($text !== null) {
                $this->event($order, OrderEventType::Note, 'event:'.$e['id'], $this->date($e['created_at'] ?? null) ?? $created, [
                    'note' => Str::limit($text, 1000),
                    'actor_name' => $this->text($e['client'] ?? null) ?? 'Ancienne application',
                ]);
            }
        }

        foreach ($c['relances'] ?? [] as $r) {
            $to = $this->day($r['relance_date'] ?? $r['current_date'] ?? null);
            if ($to !== null) {
                $from = $this->day($r['previous_date'] ?? null);
                $note = $this->text($r['relance_note'] ?? null);
                $this->event($order, OrderEventType::Note, 'relance:'.(isset($r['relance_date']) ? 'd' : 'r').$r['id'], $this->date($r['created_at'] ?? null) ?? $created, [
                    'note' => 'Livraison reportée'.($from ? ' du '.Carbon::parse($from)->format('d/m') : '').' au '.Carbon::parse($to)->format('d/m/Y').($note ? " : {$note}" : ''),
                ]);
            }
        }

        foreach ($c['frais'] ?? [] as $f) {
            $this->event($order, OrderEventType::Note, 'frais:'.$f['id'], $this->date($f['created_at'] ?? null) ?? $created, [
                'note' => Str::limit('Frais (ancienne application) : '.Money::format((int) ($f['montant'] ?? 0)).' — '.($this->text($f['description'] ?? null) ?? ''), 1000),
            ]);
        }

        if ($plan['failed_note'] !== null) {
            $this->event($order, OrderEventType::Incident, 'undelivered:'.md5($plan['failed_note']), $this->date($c['updated_at'] ?? null) ?? $created, [
                'note' => Str::limit('Non livrée : '.$plan['failed_note'], 1000),
            ]);
        }

        // État actuel : un changement d'état dans l'ancienne application est noté une fois
        $this->event($order, OrderEventType::StatusChanged, 'status:'.$plan['status']->value, $this->date($c['updated_at'] ?? null) ?? $created, [
            'to_status' => $plan['status'],
            'note' => 'État dans l\'ancienne application : '.trim((string) ($c['etat'] ?? '')),
        ]);
    }

    /**
     * Argent : écritures de l'e-commerçant pour ce qui lui est encore dû, encaissement chez le livreur tant qu'il ne
     * l'a pas remis ; un reversement fait dans l'ancienne application solde les écritures de la course.
     */
    private function finance(Order $order, array $plan, array $money, ?Courier $courier): void
    {
        $delivered = $plan['status'] === OrderStatus::Delivered;
        $entries = MerchantLedgerEntry::query()->where('order_id', $order->id)->where('type', '!=', LedgerEntryType::Payout->value)->get();
        $collection = CashCollection::query()->where('order_id', $order->id)->first();

        // Plus livrée (annulée, retour…) : rien n'est dû, rien n'est chez le livreur
        if (! $delivered) {
            $entries->whereNull('payout_id')->each->delete();
            if ($collection && $collection->remittance_id === null) {
                $collection->delete();
            }

            return;
        }

        // Encaissement : chez le livreur tant que la remise à la caisse n'est pas faite dans l'ancienne application
        if ($money['courier_cash'] > 0 || ($collection && $collection->remittance_id === null)) {
            $collection ??= new CashCollection;
            // Réglé au point de départ de l'argent chez les livreurs : ne revient pas chez le livreur
            $baseline = $collection->legacy_cash_baseline_id !== null && $collection->received_by_company;
            $collection->forceFill([
                'company_id' => $this->company->id,
                'order_id' => $order->id,
                'courier_id' => $courier?->id,
                'amount_expected' => $money['cod'],
                'amount_collected' => $money['cod'],
                'method' => PaymentMethod::Cash,
                'received_by_company' => $baseline || $money['courier_cash'] === 0,
                'transaction_ref' => $baseline ? LegacyCashBaselines::REF : ($money['courier_cash'] === 0 ? 'Remis dans l\'ancienne application' : null),
                'collected_at' => $order->delivered_at ?? now(),
            ])->save();
        }

        // Déjà reversé dans l'ancienne application avant la reprise : aucune écriture
        if ($money['paid'] && $entries->isEmpty()) {
            return;
        }

        if ($entries->isEmpty()) {
            $this->ledger($order, LedgerEntryType::CodCredit, $money['cod'], 'Encaissé à la livraison (ancienne application)');
            $this->ledger($order, LedgerEntryType::DeliveryFee, -$money['fee'], 'Frais de livraison');
            if ($money['owed'] !== $money['items']) {
                $this->ledger($order, LedgerEntryType::Adjustment, $money['owed'] - $money['items'], 'Écart avec le paiement de l\'ancienne application');
            }
            $entries = MerchantLedgerEntry::query()->where('order_id', $order->id)->where('type', '!=', LedgerEntryType::Payout->value)->get();
        }

        // Reversé depuis dans l'ancienne application : écritures soldées par un reversement « ancienne application »
        $open = $entries->whereNull('payout_id');
        if ($money['paid'] && $open->isNotEmpty()) {
            $payout = $this->legacyPayout($order->merchant_id);
            MerchantLedgerEntry::whereIn('id', $open->modelKeys())->update(['payout_id' => $payout->id]);
            $amount = (int) $open->sum('amount');
            $payout->forceFill([
                'total_collected' => $payout->total_collected + (int) $open->where('type', LedgerEntryType::CodCredit)->sum('amount'),
                'total_fees' => $payout->total_fees - (int) $open->where('type', LedgerEntryType::DeliveryFee)->sum('amount'),
                'total_adjustments' => $payout->total_adjustments + (int) $open->where('type', LedgerEntryType::Adjustment)->sum('amount'),
                'net_amount' => $payout->net_amount + $amount,
                'period_end' => now(),
            ])->save();
            MerchantLedgerEntry::create([
                'company_id' => $this->company->id,
                'merchant_id' => $order->merchant_id,
                'type' => LedgerEntryType::Payout,
                'amount' => -$amount,
                'payout_id' => $payout->id,
                'order_id' => $order->id,
                'description' => "Reversé dans l'ancienne application ({$payout->reference})",
            ]);
        }
    }

    /** Reversement du jour qui regroupe ce qui a été payé dans l'ancienne application (sans message à l'e-commerçant) */
    private function legacyPayout(int $merchantId): MerchantPayout
    {
        if (isset($this->payouts[$merchantId])) {
            return $this->payouts[$merchantId];
        }

        $reference = sprintf('ANC-%s-%d', now()->format('ymd'), $merchantId);
        $payout = MerchantPayout::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id, 'reference' => $reference],
            [
                'merchant_id' => $merchantId,
                'period_start' => now(),
                'period_end' => now(),
                'total_collected' => 0, 'total_fees' => 0, 'total_shipping_fees' => 0, 'total_other_fees' => 0,
                'total_storage_fees' => 0, 'total_adjustments' => 0, 'net_amount' => 0,
                'status' => PayoutStatus::Paid,
                'method' => PaymentMethod::Cash,
                'transaction_ref' => 'Ancienne application',
                'notes' => 'Reversé dans l\'ancienne application',
                'paid_at' => now(),
            ],
        );

        return $this->payouts[$merchantId] = $payout;
    }

    private function ledger(Order $order, LedgerEntryType $type, int $amount, string $description): void
    {
        if ($amount === 0) {
            return;
        }

        MerchantLedgerEntry::create([
            'company_id' => $this->company->id,
            'merchant_id' => $order->merchant_id,
            'type' => $type,
            'amount' => $amount,
            'order_id' => $order->id,
            'description' => $description,
        ]);
    }

    /** Ligne d'historique écrite une seule fois (clé « legacy »), sans notification */
    private function event(Order $order, OrderEventType $type, string $key, Carbon $at, array $attributes): void
    {
        $exists = OrderEvent::query()->where('order_id', $order->id)->where('meta->legacy', $key)->exists();
        if ($exists) {
            return;
        }

        OrderEvent::create([
            'order_id' => $order->id,
            'type' => $type,
            'actor_type' => 'system',
            'actor_name' => $attributes['actor_name'] ?? 'Ancienne application',
            'visible_to_merchant' => false,
            'created_at' => $at,
            ...$attributes,
            'meta' => ['legacy' => $key],
        ]);
    }

    private function recipient(Merchant $merchant, Order $order): ?Recipient
    {
        $recipient = Recipient::forCompany($this->company->id)->firstOrNew(['merchant_id' => $merchant->id, 'phone' => $order->recipient_phone]);
        $recipient->fill(array_filter([
            'company_id' => $this->company->id,
            'name' => $order->recipient_name,
            'phone2' => $order->recipient_phone2,
            'zone_id' => $order->delivery_zone_id,
            'address' => $order->delivery_address,
        ], fn ($v) => $v !== null))->save();

        return $recipient;
    }

    /** E-commerçant d'une ancienne fiche « client » (pour la reprise des produits) */
    public function merchantFor(?array $client, mixed $legacyId): ?Merchant
    {
        return $this->merchant($client, $legacyId);
    }

    /**
     * E-commerçant de l'ancienne fiche « client » : par son ancien numéro, sinon par son téléphone (reprise des
     * comptes faite avant), puis l'ancien numéro est retenu.
     */
    private function merchant(?array $client, mixed $legacyId): ?Merchant
    {
        $legacyId = (string) $legacyId;
        if ($legacyId === '') {
            return null;
        }
        if (array_key_exists($legacyId, $this->merchants)) {
            return $this->merchants[$legacyId];
        }

        $merchant = Merchant::forCompany($this->company->id)->with('pickupZone')->where('legacy_id', $legacyId)->first();
        $phone = PhoneNumber::normalize((string) ($client['phone'] ?? ''));
        if ($merchant === null && $phone !== null) {
            $merchant = Merchant::forCompany($this->company->id)->with('pickupZone')->whereNull('legacy_id')
                ->where(fn ($q) => $q->where('phone', $phone)->orWhereHas('users', fn ($u) => $u->where('phone', $phone)))
                ->orderBy('id')->first();
            $merchant?->forceFill(['legacy_id' => $legacyId])->save();
        }

        return $this->merchants[$legacyId] = $merchant;
    }

    private function courier(?array $livreur, mixed $legacyId): ?Courier
    {
        $legacyId = (string) $legacyId;
        if ($legacyId === '' || $legacyId === '0') {
            return null;
        }
        if (array_key_exists($legacyId, $this->couriers)) {
            return $this->couriers[$legacyId];
        }

        $courier = Courier::forCompany($this->company->id)->where('legacy_id', $legacyId)->first();
        $phone = PhoneNumber::normalize((string) ($livreur['phone'] ?? ''));
        if ($courier === null && $phone !== null) {
            $courier = Courier::forCompany($this->company->id)->whereNull('legacy_id')
                ->whereHas('user', fn ($u) => $u->withoutGlobalScopes()->where('phone', $phone))->orderBy('id')->first();
            $courier?->forceFill(['legacy_id' => $legacyId])->save();
        }

        return $this->couriers[$legacyId] = $courier;
    }

    /** Zone d'un nom de commune ou de quartier de l'ancienne application, s'il n'y en a qu'une */
    public function zoneNamed(?string $name): ?Zone
    {
        return $this->zone($name);
    }

    private function zone(?string $name): ?Zone
    {
        $name = $this->text($name);
        if ($name === null || $this->zones->isEmpty()) {
            return null;
        }
        // Prix écrits dans le nom (« Songon 2500F »), comme pour la reprise des zones
        $name = trim(preg_replace('/\s*\d[\d\s.]*\s*f(cfa)?\b/iu', '', $name));
        $found = $this->zoneResolver->match($name, $this->zones->where('is_active', true)->values());

        return $found->count() === 1 ? $found->first() : null;
    }

    private function unknownZone(): Zone
    {
        return $this->unknownZone ??= Zone::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id, 'name' => self::UNKNOWN_ZONE, 'parent_id' => null],
            ['is_active' => false],
        );
    }

    private function switched(): bool
    {
        return $this->company->legacy_switch_at !== null && $this->company->legacy_switch_at->isPast();
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function position(mixed $lat, mixed $lng): array
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return [null, null];
        }
        $lat = (float) $lat;
        $lng = (float) $lng;

        return abs($lat) <= 90 && abs($lng) <= 180 && ($lat != 0 || $lng != 0) ? [$lat, $lng] : [null, null];
    }

    private function date(mixed $value): ?Carbon
    {
        $value = $this->text(is_string($value) ? $value : null);
        if ($value === null || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function day(mixed $value): ?string
    {
        return $this->date($value)?->toDateString();
    }

    private function text(mixed $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private function limit(mixed $value, int $length): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : Str::limit($value, $length, '');
    }
}
