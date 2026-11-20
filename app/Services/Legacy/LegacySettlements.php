<?php

namespace App\Services\Legacy;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\LegacySettlement;
use App\Models\Merchant;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * « Solder l'ancien » : les sommes encore dues aux e-commerçants pour les courses de l'ancienne application livrées
 * avant une date sont marquées réglées hors Jibiat (un reversement « payé » par e-commerçant, sans message ni
 * webhook), que leur argent soit à la caisse ou encore noté chez un livreur. En option, l'argent de ces courses
 * encore noté chez les livreurs est compté remis à la caisse. Les courses créées dans Jibiat ne bougent pas.
 * Annulable pendant 7 jours.
 */
class LegacySettlements
{
    public const CASH_REF = 'Soldé hors Jibiat (ancienne application)';

    /** Écritures non reversées des courses de l'ancienne application livrées avant la date */
    public function entries(Company $company, CarbonInterface $before): Builder
    {
        return MerchantLedgerEntry::withoutGlobalScopes()
            ->where('merchant_ledger_entries.company_id', $company->id)
            ->whereNull('payout_id')
            ->where('type', '!=', LedgerEntryType::Payout->value)
            ->whereHas('order', fn ($q) => $this->legacyBefore($q->withoutGlobalScopes(), $before));
    }

    /** Argent de ces courses encore noté chez un livreur */
    public function cash(Company $company, CarbonInterface $before): Builder
    {
        return CashCollection::withoutGlobalScopes()
            ->where('cash_collections.company_id', $company->id)
            ->inCourierHands()
            ->whereHas('order', fn ($q) => $this->legacyBefore($q->withoutGlobalScopes(), $before));
    }

    /**
     * Simulation : par e-commerçant (les plus gros d'abord), et les totaux.
     *
     * @return array{merchants: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function simulate(Company $company, CarbonInterface $before): array
    {
        $rows = $this->entries($company, $before)
            ->selectRaw('merchant_id, SUM(amount) AS total, COUNT(DISTINCT order_id) AS orders')
            ->groupBy('merchant_id')->get();
        $names = Merchant::withoutGlobalScopes()->whereIn('id', $rows->pluck('merchant_id'))->pluck('business_name', 'id');
        $cash = $this->cash($company, $before);

        return [
            'merchants' => $rows->map(fn ($r) => [
                'merchant_id' => (int) $r->merchant_id,
                'name' => $names[$r->merchant_id] ?? 'E-commerçant n° '.$r->merchant_id,
                'amount' => (int) $r->total,
                'orders' => (int) $r->orders,
            ])->sortByDesc(fn ($m) => abs($m['amount']))->values()->take(50)->all(),
            'totals' => [
                'merchants' => $rows->count(),
                'orders' => (int) $rows->sum('orders'),
                'amount' => (int) $rows->sum('total'),
                'cash_count' => (clone $cash)->count(),
                'cash_amount' => (int) (clone $cash)->sum('amount_collected'),
            ],
        ];
    }

    public function settle(Company $company, User $by, CarbonInterface $before, bool $clearCash): LegacySettlement
    {
        $simulation = $this->simulate($company, $before);
        if ($simulation['totals']['merchants'] === 0 && (! $clearCash || $simulation['totals']['cash_count'] === 0)) {
            throw new RuntimeException('Rien à solder avant cette date.');
        }

        return DB::transaction(function () use ($company, $by, $before, $clearCash) {
            $settlement = LegacySettlement::create([
                'company_id' => $company->id,
                'user_id' => $by->id,
                'before' => $before->toDateString(),
                'clear_cash' => $clearCash,
            ]);

            $merchants = 0;
            $count = 0;
            $amount = 0;
            $merchantIds = $this->entries($company, $before)->distinct()->pluck('merchant_id');
            foreach ($merchantIds as $merchantId) {
                $entries = $this->entries($company, $before)->where('merchant_id', $merchantId)->lockForUpdate()->get();
                if ($entries->isEmpty()) {
                    continue;
                }
                $sum = (int) $entries->sum('amount');
                $payout = MerchantPayout::withoutGlobalScopes()->forceCreate([
                    'company_id' => $company->id,
                    'merchant_id' => $merchantId,
                    'reference' => sprintf('SOLDE-%s-%d-%d', now()->format('ymd'), $settlement->id, $merchantId),
                    'period_start' => $before->copy()->subYears(10)->toDateString(),
                    'period_end' => $before->copy()->subDay()->toDateString(),
                    'total_collected' => (int) $entries->where('type', LedgerEntryType::CodCredit)->sum('amount'),
                    'total_fees' => -(int) $entries->where('type', LedgerEntryType::DeliveryFee)->sum('amount'),
                    'total_shipping_fees' => 0, 'total_other_fees' => 0, 'total_storage_fees' => 0,
                    'total_adjustments' => (int) $entries->whereNotIn('type', [LedgerEntryType::CodCredit, LedgerEntryType::DeliveryFee])->sum('amount'),
                    'net_amount' => $sum,
                    'status' => PayoutStatus::Paid,
                    'method' => PaymentMethod::Cash,
                    'transaction_ref' => 'Hors Jibiat',
                    'notes' => 'Ancienne application : réglé hors Jibiat (solde du '.now()->format('d/m/Y').')',
                    'created_by' => $by->id,
                    'paid_by' => $by->id,
                    'paid_at' => now(),
                    'legacy_settlement_id' => $settlement->id,
                ]);
                foreach (array_chunk($entries->modelKeys(), 500) as $chunk) {
                    MerchantLedgerEntry::withoutGlobalScopes()->whereIn('id', $chunk)->update(['payout_id' => $payout->id]);
                }
                MerchantLedgerEntry::withoutGlobalScopes()->forceCreate([
                    'company_id' => $company->id,
                    'merchant_id' => $merchantId,
                    'type' => LedgerEntryType::Payout,
                    'amount' => -$sum,
                    'payout_id' => $payout->id,
                    'description' => "Réglé hors Jibiat, ancienne application ({$payout->reference})",
                    'created_by' => $by->id,
                ]);
                $merchants++;
                $count += $entries->count();
                $amount += $sum;
            }

            $cashCount = 0;
            $cashAmount = 0;
            if ($clearCash) {
                $collections = $this->cash($company, $before)->get(['cash_collections.id', 'amount_collected']);
                foreach (array_chunk($collections->modelKeys(), 500) as $chunk) {
                    CashCollection::withoutGlobalScopes()->whereIn('id', $chunk)->update([
                        'received_by_company' => true,
                        'company_confirmed_at' => now(),
                        'transaction_ref' => self::CASH_REF,
                        'legacy_settlement_id' => $settlement->id,
                        'updated_at' => now(),
                    ]);
                }
                $cashCount = $collections->count();
                $cashAmount = (int) $collections->sum('amount_collected');
            }

            $settlement->forceFill([
                'merchants_count' => $merchants,
                'entries_count' => $count,
                'amount' => $amount,
                'cash_count' => $cashCount,
                'cash_amount' => $cashAmount,
            ])->save();

            return $settlement;
        });
    }

    /** Annulation (7 jours) : les sommes redeviennent dues, l'argent revient chez les livreurs */
    public function undo(LegacySettlement $settlement): void
    {
        if (! $settlement->canBeUndone()) {
            throw new RuntimeException('Ce solde ne peut plus être annulé.');
        }

        DB::transaction(function () use ($settlement) {
            $payouts = MerchantPayout::withoutGlobalScopes()->where('legacy_settlement_id', $settlement->id)->pluck('id');
            foreach ($payouts->chunk(200) as $chunk) {
                MerchantLedgerEntry::withoutGlobalScopes()->whereIn('payout_id', $chunk)->where('type', LedgerEntryType::Payout->value)->delete();
                MerchantLedgerEntry::withoutGlobalScopes()->whereIn('payout_id', $chunk)->update(['payout_id' => null]);
                MerchantPayout::withoutGlobalScopes()->whereIn('id', $chunk)->delete();
            }
            CashCollection::withoutGlobalScopes()->where('legacy_settlement_id', $settlement->id)->whereNull('remittance_id')
                ->where('transaction_ref', self::CASH_REF)
                ->update(['received_by_company' => false, 'company_confirmed_at' => null, 'transaction_ref' => null, 'legacy_settlement_id' => null, 'updated_at' => now()]);
            $settlement->forceFill(['status' => 'undone', 'undone_at' => now()])->save();
        });
    }

    /** @return array<string, mixed>|null */
    public function describe(?LegacySettlement $settlement): ?array
    {
        return $settlement ? [
            'id' => $settlement->id,
            'status' => $settlement->status,
            'before' => $settlement->before->toDateString(),
            'clear_cash' => $settlement->clear_cash,
            'merchants' => $settlement->merchants_count,
            'entries' => $settlement->entries_count,
            'amount' => $settlement->amount,
            'cash_count' => $settlement->cash_count,
            'cash_amount' => $settlement->cash_amount,
            'by' => $settlement->user?->name,
            'created_at' => $settlement->created_at->toIso8601String(),
            'undone_at' => $settlement->undone_at?->toIso8601String(),
            'can_undo' => $settlement->canBeUndone(),
            'undo_until' => $settlement->created_at->copy()->addDays(LegacySettlement::UNDO_DAYS)->toIso8601String(),
        ] : null;
    }

    /** Course de l'ancienne application livrée (sinon créée) avant la date */
    private function legacyBefore(Builder $orders, CarbonInterface $before): Builder
    {
        $day = $before->toDateString();

        return $orders->whereNotNull('legacy_id')
            ->where(fn ($q) => $q->where('delivered_at', '<', $day)->orWhere(fn ($q) => $q->whereNull('delivered_at')->where('created_at', '<', $day)));
    }
}
