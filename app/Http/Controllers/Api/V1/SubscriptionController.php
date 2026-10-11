<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Models\PlatformSetting;
use App\Services\Platform\Billing;
use App\Services\Platform\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Mon abonnement » : formule, essai, consommation du mois, factures et paiement.
 */
class SubscriptionController extends Controller
{
    public function show(Request $request, Subscription $subscription, Billing $billing): JsonResponse
    {
        $company = $request->user()->company;
        abort_if($company === null, 404, 'Aucune entreprise.');

        return response()->json(['data' => [
            ...$subscription->summary($company),
            'billing' => $billing->state($company),
            'payment_instructions' => PlatformSetting::get('payment_instructions'),
            'invoices' => PlatformInvoice::query()->where('company_id', $company->id)->where('status', '!=', PlatformInvoice::VOID)
                ->orderByDesc('period')->limit(24)->get()
                ->map(fn (PlatformInvoice $i) => ConsoleBillingController::invoiceData($i))->values(),
        ]]);
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
}
