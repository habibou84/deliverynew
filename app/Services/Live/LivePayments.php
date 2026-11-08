<?php

namespace App\Services\Live;

use App\Exceptions\BusinessRuleException;
use App\Models\Live;
use App\Models\LivePayment;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Payments\PayDunya;
use App\Services\Payments\PaymentException;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Paiement en ligne de l'outil live par l'e-commerçant, directement à Jibiat (PayDunya) : recharge du crédit live
 * (le prix de chaque live y est pris à la fin, selon ses commandes), le forfait du mois ou des fiches IA. Le paiement est relu chez PayDunya avant d'être appliqué (une seule fois) : le live
 * démarre, le forfait s'active ou les fiches s'ajoutent, et la part de l'agence va sur son crédit.
 */
class LivePayments
{
    /** live : ancien paiement d'un live à l'unité (avant les paliers), gardé pour les paiements en cours */
    public const KINDS = ['credit', 'monthly', 'pack', 'live'];

    public function __construct(private readonly LiveStudio $studio, private readonly PayDunya $paydunya) {}

    /**
     * Ouvre le paiement : renvoie le paiement et l'adresse de la page de PayDunya.
     *
     * @return array{payment: LivePayment, url: string}
     */
    public function checkout(Merchant $merchant, User $user, string $kind, ?Live $live, string $origin, ?int $amount = null): array
    {
        if (! $this->studio->paysOnline($merchant)) {
            throw new BusinessRuleException('Le paiement en ligne n\'est pas proposé pour votre boutique.');
        }
        $s = $this->studio->settings();
        if ($kind === 'credit' && ($amount === null || $amount < (int) $s['min_credit'])) {
            throw new BusinessRuleException('Rechargez au moins '.number_format((int) $s['min_credit'], 0, ',', ' ').' F.', 'amount');
        }
        [$amount, $label] = match ($kind) {
            'credit' => [(int) $amount, 'Crédit live'],
            'monthly' => [(int) $s['price_month'], 'Forfait live du mois'],
            'pack' => [(int) $s['pack_price'], "{$s['pack_cards']} fiches produit IA"],
            default => throw new BusinessRuleException('Paiement inconnu.'),
        };

        $payment = LivePayment::create([
            'company_id' => $merchant->company_id,
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'live_id' => $live?->id,
            'kind' => $kind,
            'amount' => $amount,
        ]);
        $back = rtrim($origin, '/')."/marchand/live/paiement/{$payment->id}";

        try {
            $invoice = $this->paydunya->createInvoice(
                $amount,
                "{$merchant->business_name} · {$label}",
                $back,
                $back.'?annule=1',
                Tenancy::platformUrl().'/api/webhooks/paydunya',
                ['payment' => $payment->id],
            );
        } catch (PaymentException $e) {
            $payment->forceFill(['status' => LivePayment::FAILED, 'error' => mb_substr($e->getMessage(), 0, 255)])->save();
            throw new BusinessRuleException($e->getMessage());
        }
        $payment->forceFill(['token' => $invoice['token']])->save();

        return ['payment' => $payment, 'url' => $invoice['url']];
    }

    /**
     * Relit l'état chez PayDunya et applique un paiement réussi (une seule fois, même si l'avis arrive deux fois).
     */
    public function refresh(LivePayment $payment): LivePayment
    {
        if ($payment->status !== LivePayment::PENDING || blank($payment->token)) {
            return $payment;
        }
        try {
            $state = $this->paydunya->confirm($payment->token);
        } catch (PaymentException) {
            return $payment;
        }

        return match ($state['status']) {
            'completed' => $this->apply($payment, $state),
            'cancelled', 'failed' => tap($payment)->forceFill(['status' => $state['status'] === 'cancelled' ? LivePayment::CANCELLED : LivePayment::FAILED])->save(),
            default => $payment,
        };
    }

    /** @param  array{status: string, amount: int, method: ?string}  $state */
    private function apply(LivePayment $payment, array $state): LivePayment
    {
        return DB::transaction(function () use ($payment, $state) {
            $payment = LivePayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== LivePayment::PENDING) {
                return $payment;
            }
            if ($state['amount'] < $payment->amount) {
                $payment->forceFill(['status' => LivePayment::FAILED, 'error' => "Montant payé ({$state['amount']} F) inférieur au prix."])->save();

                return $payment;
            }
            $payment->forceFill(['status' => LivePayment::PAID, 'paid_at' => now(), 'method' => $state['method']])->save();

            $merchant = Merchant::withoutGlobalScopes()->with('company')->findOrFail($payment->merchant_id);
            $user = User::withoutGlobalScopes()->find($payment->user_id)
                ?? User::withoutGlobalScopes()->where('merchant_id', $merchant->id)->orderBy('id')->firstOrFail();
            match ($payment->kind) {
                'credit' => $this->studio->addCredit($merchant, $payment),
                'live' => $this->startPaidLive($payment, $user),
                'monthly' => $this->studio->subscribeMonthly($merchant, $user, $payment),
                'pack' => $this->studio->buyPack($merchant, $user, $payment),
            };

            return $payment->refresh();
        });
    }

    private function startPaidLive(LivePayment $payment, User $user): void
    {
        $live = Live::withoutGlobalScopes()->find($payment->live_id);
        if ($live === null || $live->status !== Live::DRAFT) {
            // Live supprimé ou déjà démarré : le paiement reste dû à l'e-commerçant (à voir dans la console)
            $payment->forceFill(['error' => 'Live déjà démarré ou supprimé : à rembourser ou à reporter.'])->save();

            return;
        }
        $this->studio->start($live, $user, $payment);
    }
}
