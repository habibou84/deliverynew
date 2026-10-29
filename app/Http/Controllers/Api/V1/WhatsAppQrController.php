<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp › Connexion par QR code, en attendant la validation de Meta : afficher le QR code à scanner depuis
 * « Appareils connectés » sur le téléphone, suivre l'état, déconnecter. Réservée aux entreprises à qui la console
 * l'autorise (connexion non officielle).
 */
class WhatsAppQrController extends Controller
{
    public function __construct(private readonly WhatsAppBridge $bridge) {}

    public function show(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $account = $this->qrAccount($company);

        return response()->json(['data' => $this->describe($company, $account, $account ? $this->live($account) : null)]);
    }

    /** Démarre la session et renvoie le QR code (ou l'état s'il est déjà relié) */
    public function start(Request $request): JsonResponse
    {
        $company = $this->company($request);
        if (! $company->whatsapp_qr_enabled) {
            throw ValidationException::withMessages(['qr' => 'La connexion par QR code n\'est pas activée pour votre entreprise : utilisez le bouton « Connecter WhatsApp ».']);
        }
        $account = WhatsAppAccount::forCompanyId($company->id);
        if ($account !== null && ! $account->isQr() && $account->status === 'connected') {
            throw ValidationException::withMessages(['qr' => 'Votre numéro est déjà connecté par Meta (connexion officielle) : la connexion par QR code n\'est pas nécessaire.']);
        }

        $account ??= new WhatsAppAccount(['company_id' => $company->id, 'owner_type' => 'company']);
        $account->forceFill(['provider' => WhatsAppAccount::QR, 'status' => $account->isQr() && $account->status === 'connected' ? 'connected' : 'pending'])->save();

        try {
            $live = $this->bridge->start($account->bridgeSession());
        } catch (MessagingException $e) {
            throw ValidationException::withMessages(['qr' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->describe($company, $account->fresh(), $live)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $account = $this->qrAccount($company);
        if ($account !== null) {
            try {
                $this->bridge->logout($account->bridgeSession());
            } catch (MessagingException) {
                // Service injoignable : le compte est tout de même marqué déconnecté
            }
            $account->forceFill(['status' => 'disconnected', 'last_error' => null])->save();
        }

        return response()->json(['data' => $this->describe($company, $account?->fresh(), null)]);
    }

    private function qrAccount(Company $company): ?WhatsAppAccount
    {
        $account = WhatsAppAccount::forCompanyId($company->id);

        return $account?->isQr() ? $account : null;
    }

    /**
     * État vu par le service (QR code en cours) ; null s'il est injoignable.
     *
     * @return array{status: string, qr: ?string, phone: ?string, name: ?string}|null
     */
    private function live(WhatsAppAccount $account): ?array
    {
        try {
            return $this->bridge->status($account->bridgeSession());
        } catch (MessagingException) {
            return null;
        }
    }

    /**
     * @param  array{status: string, qr: ?string, phone: ?string, name: ?string}|null  $live
     * @return array<string, mixed>
     */
    private function describe(Company $company, ?WhatsAppAccount $account, ?array $live): array
    {
        $connected = $account?->status === 'connected';

        return [
            'available' => WhatsAppBridge::configured() && $company->whatsapp_qr_enabled,
            'active' => $account !== null,
            // pending : QR code à scanner ; connected : numéro relié ; disconnected : à reconnecter
            'status' => $account === null ? 'none' : ($connected ? 'connected' : ($account->status === 'disconnected' ? 'disconnected' : 'pending')),
            'qr' => $connected ? null : ($live['qr'] ?? null),
            'service' => $live['status'] ?? null,
            'phone' => $account?->display_phone,
            'name' => $account?->verified_name,
            'connected_at' => $account?->connected_at,
            'last_error' => $account?->last_error,
        ];
    }

    private function company(Request $request): Company
    {
        $company = $request->user()->company;
        abort_if($company === null, 422, 'Choisissez une entreprise.');

        return $company;
    }
}
