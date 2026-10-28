<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Models\PlatformWavePayment;
use App\Rules\PhoneNumber as PhoneRule;
use App\Services\Platform\AppOptions;
use App\Services\Platform\Billing;
use App\Services\Platform\Subscription;
use App\Services\Platform\WavePayments;
use App\Services\Platform\WaveSettings;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Mon abonnement » : formule, essai, consommation du mois, factures et paiement.
 */
class SubscriptionController extends Controller
{
    public function show(Request $request, Subscription $subscription, Billing $billing, WaveSettings $wave): JsonResponse
    {
        $company = $request->user()->company;
        abort_if($company === null, 404, 'Aucune entreprise.');

        return response()->json(['data' => [
            ...$subscription->summary($company),
            'billing' => $billing->state($company),
            'payment_instructions' => PlatformSetting::get('payment_instructions'),
            // Paiement par le lien marchand Wave (QR code pour payer depuis un ordinateur)
            'wave' => $wave->enabled() ? ['qr_url' => $wave->qrUrl()] : null,
            // Paiements Wave annoncés et pas encore vérifiés, par facture
            'wave_pending' => PlatformWavePayment::query()->where('company_id', $company->id)->where('status', PlatformWavePayment::PENDING)
                ->get()->map(fn (PlatformWavePayment $p) => self::wavePayment($p, $wave))->values(),
            'invoices' => PlatformInvoice::query()->where('company_id', $company->id)->where('status', '!=', PlatformInvoice::VOID)
                ->orderByDesc('period')->limit(24)->get()
                ->map(fn (PlatformInvoice $i) => ConsoleBillingController::invoiceData($i))->values(),
        ]]);
    }

    /**
     * Option « Applications à votre nom » demandée à Jibiat (Android ; iPhone bientôt).
     */
    public function requestApps(Request $request, AppOptions $options, Subscription $subscription, string $platform): JsonResponse
    {
        abort_unless(in_array($platform, AppOptions::PLATFORMS, true), 404);
        $company = $request->user()->company;
        abort_if($company === null, 404, 'Aucune entreprise.');
        $options->request($company, $request->user(), $platform);

        return response()->json(['data' => $options->state($company), 'message' => 'Demande envoyée à Jibiat : vous serez prévenu dès qu\'elle est validée.'], 201);
    }

    /**
     * Option arrêtée (ou demande retirée) : plus facturée à partir de demain ; l'application déjà installée continue
     * de marcher, sans nouvelle version.
     */
    public function stopApps(Request $request, AppOptions $options, string $platform): JsonResponse
    {
        abort_unless(in_array($platform, AppOptions::PLATFORMS, true), 404);
        $company = $request->user()->company;
        $option = $company ? $options->current($company, $platform) : null;
        abort_if($option === null, 404, 'Aucune option en cours.');
        $options->stop($option, $request->user());

        return response()->json(['data' => $options->state($company)]);
    }

    /**
     * Facture de l'entreprise (version imprimable).
     */
    public function invoice(Request $request, PlatformInvoice $invoice): JsonResponse
    {
        abort_unless($invoice->company_id === $request->user()->company_id, 404, 'Facture introuvable.');

        return response()->json(['data' => [
            ...ConsoleBillingController::invoiceData($invoice->load(['company', 'payments.recorder']), withPayments: true),
            ...ConsoleBillingController::documentSettings(),
        ]]);
    }

    /**
     * Rythme de facturation : au mois ou à la semaine, à partir du lundi suivant.
     */
    public function updateCycle(Request $request, Billing $billing, Subscription $subscription): JsonResponse
    {
        $company = $request->user()->company;
        abort_if($company === null, 404, 'Aucune entreprise.');
        $data = $request->validate(['billing_cycle' => ['required', 'in:monthly,weekly']], [], ['billing_cycle' => 'rythme de facturation']);
        $billing->setCycle($company, $data['billing_cycle']);

        return response()->json(['data' => $subscription->summary($company->fresh())]);
    }

    /**
     * « Payer avec Wave » : le paiement est annoncé (le validateur est prévenu), puis le lien et le QR code s'affichent.
     */
    public function waveStart(Request $request, PlatformInvoice $invoice, WavePayments $payments, WaveSettings $wave): JsonResponse
    {
        abort_unless($invoice->company_id === $request->user()->company_id, 404, 'Facture introuvable.');
        $data = $request->validate(['payer_phone' => ['required', new PhoneRule]], [], ['payer_phone' => 'numéro Wave']);

        $payment = $payments->start($invoice, $request->user(), PhoneNumber::display(PhoneNumber::normalize($data['payer_phone'])));

        return response()->json(['data' => self::wavePayment($payment, $wave)], 201);
    }

    /**
     * « J'ai payé » : le validateur est prévenu une seconde fois.
     */
    public function waveClaim(Request $request, PlatformWavePayment $payment, WavePayments $payments, WaveSettings $wave): JsonResponse
    {
        abort_unless($payment->company_id === $request->user()->company_id, 404, 'Paiement introuvable.');

        return response()->json(['data' => self::wavePayment($payments->claim($payment), $wave)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function wavePayment(PlatformWavePayment $p, WaveSettings $wave): array
    {
        return [
            'id' => $p->id, 'invoice_id' => $p->platform_invoice_id, 'amount' => $p->amount, 'payer_phone' => $p->payer_phone,
            'status' => $p->status, 'claimed_at' => $p->claimed_at?->toIso8601String(), 'link' => $wave->linkFor($p->amount),
        ];
    }
}
