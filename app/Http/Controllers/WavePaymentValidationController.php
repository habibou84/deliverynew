<?php

namespace App\Http\Controllers;

use App\Models\PlatformWavePayment;
use App\Services\Platform\WavePayments;
use App\Services\Platform\WaveSettings;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Validation d'un paiement Wave depuis le lien signé reçu par WhatsApp, e-mail ou SMS, avec le code du validateur.
 */
class WavePaymentValidationController extends Controller
{
    public function __construct(private readonly WavePayments $payments, private readonly WaveSettings $settings) {}

    public function show(PlatformWavePayment $payment): View
    {
        return $this->page($payment, null);
    }

    public function decide(Request $request, PlatformWavePayment $payment): View
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40'], 'decision' => ['required', 'in:received,missing']]);

        // 5 essais par quart d'heure, par adresse et par paiement (plusieurs adresses ne suffisent pas à deviner le code)
        $keys = ['wave-validate:'.$request->ip(), 'wave-validate-payment:'.$payment->id];
        if (collect($keys)->contains(fn (string $key) => RateLimiter::tooManyAttempts($key, 5))) {
            return $this->page($payment, 'Trop d\'essais. Réessayez dans quelques minutes.');
        }
        if (! $this->settings->checkPin((string) $data['code'])) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, 900);
            }

            return $this->page($payment, 'Code incorrect.');
        }
        foreach ($keys as $key) {
            RateLimiter::clear($key);
        }

        return $this->page($this->payments->decide($payment, $data['decision'] === 'received', null), null);
    }

    private function page(PlatformWavePayment $payment, ?string $error): View
    {
        $payment->loadMissing(['invoice', 'company']);

        return view('platform.wave-validate', ['payment' => $payment, 'amount' => Money::format($payment->amount), 'error' => $error]);
    }
}
