<?php

namespace App\Services\Finance;

use App\Enums\EarningType;
use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\CourierPayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paie des livreurs : regroupe les gains non payés (commissions, retenues).
 */
class CourierPayroll
{
    /**
     * @return array{unpaid: int, cash_in_hand: int}
     */
    public function summary(Courier $courier): array
    {
        return [
            'unpaid' => (int) $courier->earnings()->whereNull('payout_id')->sum('amount'),
            'cash_in_hand' => (int) $courier->collections()->inCourierHands()->sum(DB::raw(CashCollection::amountDueSql())),
        ];
    }

    public function create(User $actor, Courier $courier): CourierPayout
    {
        return DB::transaction(function () use ($actor, $courier) {
            $earnings = CourierEarning::where('courier_id', $courier->id)->whereNull('payout_id')->lockForUpdate()->get();

            if ($earnings->isEmpty()) {
                throw new BusinessRuleException('Aucun gain à payer pour ce livreur.', 'courier_id');
            }

            $payout = CourierPayout::create([
                'company_id' => $courier->company_id,
                'courier_id' => $courier->id,
                'reference' => 'TMP-'.Str::random(20),
                'period_start' => $earnings->min('created_at'),
                'period_end' => $earnings->max('created_at'),
                'amount' => $earnings->sum('amount'),
                'status' => PayoutStatus::Draft,
                'created_by' => $actor->id,
            ]);
            $payout->forceFill(['reference' => sprintf('PL-%s-%05d', now()->format('ymd'), $payout->id)])->save();

            $earnings->each(fn (CourierEarning $e) => $e->forceFill(['payout_id' => $payout->id])->save());

            return $payout;
        });
    }

    public function markPaid(User $actor, CourierPayout $payout, PaymentMethod $method, ?string $reference = null): CourierPayout
    {
        return DB::transaction(function () use ($actor, $payout, $method, $reference) {
            $payout = CourierPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->ensureDraft($payout);

            $payout->forceFill([
                'status' => PayoutStatus::Paid,
                'method' => $method,
                'transaction_ref' => $reference,
                'paid_by' => $actor->id,
                'paid_at' => now(),
            ])->save();

            return $payout;
        });
    }

    public function cancel(CourierPayout $payout): CourierPayout
    {
        return DB::transaction(function () use ($payout) {
            $payout = CourierPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->ensureDraft($payout);

            $payout->earnings->each(fn (CourierEarning $e) => $e->forceFill(['payout_id' => null])->save());
            $payout->forceFill(['status' => PayoutStatus::Cancelled])->save();

            return $payout;
        });
    }

    public function adjust(User $actor, Courier $courier, int $amount, string $description): CourierEarning
    {
        if ($amount === 0) {
            throw new BusinessRuleException('Le montant de l\'ajustement ne peut pas être nul.', 'amount');
        }

        return CourierEarning::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'type' => EarningType::Adjustment,
            'amount' => $amount,
            'description' => $description,
            'created_by' => $actor->id,
        ]);
    }

    private function ensureDraft(CourierPayout $payout): void
    {
        if ($payout->status !== PayoutStatus::Draft) {
            throw new BusinessRuleException("Cette paie est déjà « {$payout->status->label()} ».", 'status');
        }
    }
}
