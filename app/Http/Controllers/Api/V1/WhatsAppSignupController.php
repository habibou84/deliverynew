<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppConnection;
use App\Support\Tenancy;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Bouton « Connecter WhatsApp » (inscription intégrée de Meta).
 *
 * Facebook n'ouvre sa fenêtre que depuis des adresses déclarées une à une dans l'application
 * Meta : elle s'ouvre donc toujours depuis l'adresse de la plateforme (jibiat.com), quelle que
 * soit l'adresse de l'entreprise. L'espace de l'entreprise remet un ticket chiffré et de courte
 * durée ; la page de la plateforme s'en sert à la place d'une session.
 */
class WhatsAppSignupController extends Controller
{
    private const TICKET_MINUTES = 20;

    public function __construct(private readonly WhatsAppConnection $connection) {}

    /**
     * Espace de l'entreprise (droit settings.manage) : adresse de la page de connexion.
     */
    public function ticket(Request $request): JsonResponse
    {
        abort_unless(WhatsAppConnection::signupAvailable(), 422, 'Le bouton « Connecter WhatsApp » n\'est pas encore configuré sur ce serveur.');
        $company = $request->user()->company;
        abort_if($company === null, 422, 'Choisissez une entreprise.');

        $ticket = Crypt::encryptString(json_encode([
            'company' => $company->id,
            'user' => $request->user()->id,
            // Page qui a ouvert la fenêtre : prévenue à la fin
            'origin' => $request->getSchemeAndHttpHost(),
            'expires' => now()->addMinutes(self::TICKET_MINUTES)->timestamp,
        ]));

        return response()->json(['data' => ['url' => Tenancy::platformUrl().'/connecter-whatsapp#'.$ticket]]);
    }

    /**
     * Application e-commerçant : relier son numéro WhatsApp Business officiellement (commandes du live),
     * sans quitter son application WhatsApp Business.
     */
    public function merchantTicket(Request $request): JsonResponse
    {
        abort_unless(WhatsAppConnection::signupAvailable(), 422, 'La connexion officielle de WhatsApp n\'est pas encore configurée sur ce serveur.');
        $user = $request->user();
        abort_unless($user->merchant_id !== null && $user->can('orders.create'), 403);

        $ticket = Crypt::encryptString(json_encode([
            'company' => $user->company_id,
            'merchant' => $user->merchant_id,
            'user' => $user->id,
            'origin' => $request->getSchemeAndHttpHost(),
            'return' => '/marchand/live/preparer',
            'expires' => now()->addMinutes(self::TICKET_MINUTES)->timestamp,
        ]));

        return response()->json(['data' => ['url' => Tenancy::platformUrl().'/connecter-whatsapp#'.$ticket]]);
    }

    /**
     * Page de la plateforme : entreprise concernée et identifiants publics de l'application Meta.
     */
    public function session(Request $request): JsonResponse
    {
        [$company, , $origin, $merchant, $return] = $this->resolve($request);

        return response()->json(['data' => [
            'company' => $merchant?->business_name ?? $company->name,
            'origin' => $origin,
            'return' => $return,
            // E-commerçant : numéro spécial live (sans compte WhatsApp), activé pour les commandes du live
            'merchant' => $merchant !== null,
            'embedded_signup' => [
                'app_id' => config('messaging.whatsapp.app_id'),
                'config_id' => config('messaging.whatsapp.signup_config_id'),
                'graph_version' => config('messaging.whatsapp.graph_version'),
                // Téléphone : Facebook en page entière (la petite fenêtre s'y ferme avant de démarrer). Retour sur
                // l'adresse de la plateforme, déjà déclarée chez Meta (Valid OAuth Redirect URIs), qui renvoie ici
                'return_url' => Tenancy::platformUrl().'/',
            ],
        ]]);
    }

    /**
     * Fin de la fenêtre de Meta : code d'autorisation, numéro et compte choisis par l'entreprise.
     */
    public function complete(Request $request): JsonResponse
    {
        [$company, , , $merchant] = $this->resolve($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:2000'],
            // Petite fenêtre : numéro et compte choisis ; page entière (téléphone) : retrouvés par le serveur
            'phone_number_id' => ['nullable', 'string', 'max:50', 'regex:/^\d+$/'],
            'waba_id' => ['nullable', 'string', 'max:50', 'regex:/^\d+$/'],
            'redirect_uri' => ['nullable', 'string', 'max:500', 'starts_with:'.Tenancy::platformUrl().'/'],
        ]);

        try {
            $account = $merchant !== null
                ? $this->connection->completeMerchantSignup($merchant, $data['code'], $data['phone_number_id'] ?? null, $data['waba_id'] ?? null, $data['redirect_uri'] ?? null)
                : $this->connection->completeSignup($company, $data['code'], $data['phone_number_id'] ?? null, $data['waba_id'] ?? null, $data['redirect_uri'] ?? null);
        } catch (MessagingException $e) {
            abort(422, $e->getMessage());
        }

        abort_if($account->status !== 'connected', 422, $account->last_error ?: 'Numéro enregistré, mais pas encore connecté à Meta.');

        return response()->json(['data' => [
            'verified_name' => $account->verified_name,
            'display_phone' => $account->display_phone,
            'warning' => $account->last_error,
        ]]);
    }

    /**
     * Ticket valide, non expiré, d'un utilisateur toujours autorisé à régler l'entreprise (ou, pour un
     * e-commerçant, à gérer sa boutique).
     *
     * @return array{0: Company, 1: User, 2: string, 3: ?Merchant, 4: string}
     */
    private function resolve(Request $request): array
    {
        abort_unless(WhatsAppConnection::signupAvailable(), 422, 'Le bouton « Connecter WhatsApp » n\'est pas encore configuré sur ce serveur.');
        $request->validate(['ticket' => ['required', 'string', 'max:2000']]);

        try {
            $ticket = json_decode(Crypt::decryptString((string) $request->input('ticket')), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(403, 'Lien de connexion invalide : recommencez depuis la page WhatsApp de votre espace.');
        }

        abort_if(($ticket['expires'] ?? 0) < now()->timestamp, 403, 'Lien de connexion expiré : recommencez depuis la page WhatsApp de votre espace.');

        $user = User::query()->find($ticket['user'] ?? null);
        $company = Company::query()->find($ticket['company'] ?? null);

        if (isset($ticket['merchant'])) {
            $merchant = Merchant::withoutGlobalScopes()->find($ticket['merchant']);
            abort_if(
                $user === null || $company === null || $merchant === null || $user->merchant_id !== $merchant->id
                    || $merchant->company_id !== $company->id || ! $user->isActive() || ! $user->can('orders.create'),
                403,
                'Vous n\'avez plus accès à cette boutique.',
            );

            return [$company, $user, (string) ($ticket['origin'] ?? ''), $merchant, (string) ($ticket['return'] ?? '/marchand')];
        }

        abort_if(
            $user === null || $company === null || $user->company_id !== $company->id || ! $user->isActive() || ! $user->can('settings.manage'),
            403,
            'Vous n\'avez plus accès aux paramètres de cette entreprise.',
        );

        return [$company, $user, (string) ($ticket['origin'] ?? ''), null, '/admin/whatsapp'];
    }
}
