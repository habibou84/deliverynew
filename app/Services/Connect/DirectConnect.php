<?php

namespace App\Services\Connect;

use App\Enums\MerchantStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Jobs\SendConnectDecision;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\ConnectRequest;
use App\Models\Merchant;
use App\Models\User;
use App\Notifications\ConnectRequestReceived;
use App\Notifications\MerchantSignedUp;
use App\Services\Messaging\Messenger;
use App\Services\Zones\StandardZones;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Jibiat\Socle\Contract\Contract;

/**
 * Connexion directe de Jibiat Marchand (contrat socle 1.12) : son serveur demande, requête signée, l'ouverture
 * du compte d'une boutique chez l'entreprise. Ni redirection, ni mot de passe, ni code :
 * - nouveau marchand, inscriptions ouvertes : compte créé et clé d'API rendue tout de suite ;
 * - nouveau marchand, inscriptions sur demande : l'équipe décide, la décision part chez Jibiat Marchand ;
 * - téléphone déjà client : jamais rattaché sans l'accord du marchand, demandé sur WhatsApp.
 * La clé ne passe que de serveur à serveur ; gardée chiffrée le temps de la remettre, 24 h au plus.
 */
class DirectConnect
{
    public const CLIENT = 'jibiat-marchand';

    /** Adresse chez Jibiat Marchand où part la décision (connect-decision) */
    public const DECISION_PATH = '/api/partners/v1/connections';

    /** Durée de validité du lien de confirmation envoyé au marchand déjà client */
    public const CONFIRM_LINK_HOURS = 48;

    public function __construct(private readonly AppConnect $connect, private readonly StandardZones $zones) {}

    public static function secret(): string
    {
        return (string) config('connect.clients.'.self::CLIENT.'.secret');
    }

    /**
     * Demande reçue de Jibiat Marchand ; la même « request_id » rend la même demande.
     *
     * @param  array<string, mixed>  $data  corps validé (connect-direct-request)
     * @return array{0: ConnectRequest, 1: bool} la demande, et si elle vient d'être créée
     */
    public function open(Company $company, array $data): array
    {
        $known = ConnectRequest::forCompany($company->id)->where('request_id', $data['request_id'])->first();
        if ($known !== null) {
            return [$known, false];
        }

        $base = [
            'company_id' => $company->id,
            'client_id' => self::CLIENT,
            'request_id' => $data['request_id'],
            'business_name' => trim($data['store']['name']),
            'contact_name' => trim($data['owner']['name']),
            'phone' => $data['owner']['phone'],
            'email' => filled($data['owner']['email'] ?? null) ? mb_strtolower(trim($data['owner']['email'])) : null,
            'store_reference' => (string) $data['store']['reference'],
            'store_url' => $data['store']['url'] ?? null,
            'pickup_place' => $data['pickup']['place'] ?? null,
            'pickup_zone_id' => $this->zones->zoneForPlace($company->id, $data['pickup']['place'] ?? null)?->id,
            'pickup_address' => filled($data['pickup']['address'] ?? null) ? trim($data['pickup']['address']) : null,
            'message' => filled($data['message'] ?? null) ? trim($data['message']) : null,
            'phone_verified_at' => $data['owner']['phone_verified_at'],
            'consent_at' => $data['consent_at'],
            'waiting_orders' => (int) ($data['waiting_orders'] ?? 0),
            // Un colis attend : l'entreprise a 24 h pour décider, ensuite la commande revient « à assigner »
            'decide_before' => ($data['waiting_orders'] ?? 0) > 0 ? now()->addHours(Contract::CONNECT_DIRECT_WAIT_HOURS) : null,
            'activity' => $data['activity'] ?? null,
        ];

        return DB::transaction(function () use ($company, $base) {
            // Deux appels simultanés pour la même demande : la seconde lit la première
            $known = ConnectRequest::forCompany($company->id)->where('request_id', $base['request_id'])->lockForUpdate()->first();
            if ($known !== null) {
                return [$known, false];
            }

            $existing = $this->existingAccount($company, $base['phone']);
            if ($existing === false) {
                return [$this->closed($base, ConnectRequest::DECLINED, 'Ce numéro appartient à un membre de l\'équipe de l\'entreprise.'), true];
            }
            if ($existing instanceof Merchant) {
                if ($existing->status !== MerchantStatus::Active) {
                    return [$this->closed($base + ['merchant_id' => $existing->id], ConnectRequest::DECLINED, 'Votre compte chez cette entreprise est suspendu : contactez-la.'), true];
                }

                return [$this->askConfirmation($company, $existing, $base), true];
            }

            if ($company->merchant_signup) {
                return [$this->openNow($company, $base), true];
            }

            $request = ConnectRequest::create([...$base, 'status' => ConnectRequest::PENDING]);
            DB::afterCommit(fn () => $this->notifyStaff($company, new ConnectRequestReceived($request)));

            return [$request, true];
        });
    }

    /**
     * Compte déjà ouvert avec ce numéro : le marchand, false pour un membre de l'équipe, null si aucun.
     */
    private function existingAccount(Company $company, string $phone): Merchant|false|null
    {
        $merchant = Merchant::forCompany($company->id)->where('phone', $phone)->first();
        if ($merchant !== null) {
            return $merchant;
        }

        $user = User::forCompany($company->id)->where('phone', $phone)->first();
        if ($user === null) {
            return null;
        }

        return $user->merchant_id ? (Merchant::withoutGlobalScopes()->find($user->merchant_id) ?? false) : false;
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function closed(array $base, string $status, string $reason): ConnectRequest
    {
        return ConnectRequest::create([...$base, 'status' => $status, 'decline_reason' => $reason, 'decided_at' => now(), 'decide_before' => null]);
    }

    /**
     * Inscriptions ouvertes : boutique, compte du gérant (sans mot de passe : il entre par WhatsApp ou en choisit un
     * plus tard) et clé d'API, tout de suite.
     *
     * @param  array<string, mixed>  $base
     */
    private function openNow(Company $company, array $base): ConnectRequest
    {
        $request = ConnectRequest::create([...$base, 'status' => ConnectRequest::PENDING]);
        $merchant = $this->connect->createMerchant($request);

        $this->grant($request, $merchant, null);
        DB::afterCommit(fn () => $this->notifyStaff($company, new MerchantSignedUp($merchant, fromApp: 'Jibiat Marchand')));

        return $request;
    }

    /**
     * Compte existant : le marchand confirme lui-même sur WhatsApp (lien signé), rien n'est rattaché avant.
     *
     * @param  array<string, mixed>  $base
     */
    private function askConfirmation(Company $company, Merchant $merchant, array $base): ConnectRequest
    {
        $request = ConnectRequest::create([
            ...$base, 'status' => ConnectRequest::PENDING, 'kind' => ConnectRequest::KIND_CONFIRMATION, 'merchant_id' => $merchant->id,
        ]);

        DB::afterCommit(fn () => app(Messenger::class)->merchantMessage($merchant, 'connect.confirm', WhatsAppTemplate::ConnectConfirm, [
            $merchant->contact_name ?: $merchant->business_name,
            $request->business_name,
            $company->name,
            self::confirmUrl($request),
        ]));

        return $request;
    }

    public static function confirmUrl(ConnectRequest $request): string
    {
        $company = Company::find($request->company_id);
        $path = URL::temporarySignedRoute('connect.confirm', now()->addHours(self::CONFIRM_LINK_HOURS), ['connectRequest' => $request->id], absolute: false);

        return $company->url($path);
    }

    /**
     * Réponse du marchand déjà client : « Oui » rattache sa boutique Jibiat Marchand (clé d'API), « Non » refuse.
     */
    public function confirm(ConnectRequest $request, bool $yes): ConnectRequest
    {
        return DB::transaction(function () use ($request, $yes) {
            $request = ConnectRequest::forCompany($request->company_id)->lockForUpdate()->findOrFail($request->id);
            if (! $request->isConfirmation() || $request->status !== ConnectRequest::PENDING) {
                throw new BusinessRuleException('Cette demande a déjà été traitée.');
            }

            $merchant = Merchant::withoutGlobalScopes()->find($request->merchant_id);
            if (! $yes || $merchant === null || $merchant->status !== MerchantStatus::Active) {
                $request->forceFill([
                    'status' => ConnectRequest::DECLINED, 'decided_at' => now(),
                    'decline_reason' => $yes ? 'Compte indisponible chez cette entreprise.' : 'Le marchand a refusé la connexion.',
                ])->save();
            } else {
                $this->grant($request, $merchant, null);
            }

            SendConnectDecision::dispatch($request->id)->afterCommit();

            return $request;
        });
    }

    /**
     * Demande acceptée : clé d'API au nom du gérant, gardée chiffrée pour Jibiat Marchand.
     */
    public function grant(ConnectRequest $request, Merchant $merchant, ?User $actor): void
    {
        $owner = User::withoutGlobalScopes()->where('merchant_id', $merchant->id)->orderBy('id')->get()
            ->first(fn (User $u) => $u->hasRole(Role::MerchantOwner->value));
        $client = config('connect.clients.'.self::CLIENT);
        [, $plain] = ApiKey::issue($merchant, $client['name'], $client['scopes'], $owner, client: self::CLIENT);

        $request->forceFill([
            'status' => ConnectRequest::ACCEPTED, 'merchant_id' => $merchant->id, 'decided_by' => $actor?->id,
            'decided_at' => now(), 'decide_before' => null, 'password_hash' => null,
        ])->save();
        $request->forceFill(['pending_key' => $plain])->save();
    }

    /**
     * Jibiat Marchand annule sa demande (colis confié à un autre, marchand qui renonce).
     */
    public function cancel(ConnectRequest $request): ConnectRequest
    {
        return DB::transaction(function () use ($request) {
            $request = ConnectRequest::forCompany($request->company_id)->lockForUpdate()->findOrFail($request->id);
            if ($request->status === ConnectRequest::PENDING) {
                $request->forceFill(['status' => ConnectRequest::CANCELLED, 'decided_at' => now(), 'decide_before' => null])->save();
            }

            return $request;
        });
    }

    /**
     * Demandes sans décision à temps : expirées et annoncées à Jibiat Marchand ; clés pas reprises effacées après 24 h.
     */
    public function expireDue(): int
    {
        $expired = 0;
        ConnectRequest::withoutGlobalScopes()->where('status', ConnectRequest::PENDING)->whereNotNull('decide_before')
            ->where('decide_before', '<=', now())->orderBy('id')->each(function (ConnectRequest $request) use (&$expired) {
                $done = DB::transaction(function () use ($request) {
                    $fresh = ConnectRequest::withoutGlobalScopes()->lockForUpdate()->find($request->id);
                    if ($fresh?->status !== ConnectRequest::PENDING) {
                        return false;
                    }
                    $fresh->forceFill(['status' => ConnectRequest::EXPIRED, 'decided_at' => now(), 'password_hash' => null])->save();

                    return true;
                });
                if ($done) {
                    $expired++;
                    SendConnectDecision::dispatch($request->id);
                }
            });

        ConnectRequest::withoutGlobalScopes()->whereNotNull('pending_key')->where('decided_at', '<=', now()->subHours(Contract::CONNECT_DIRECT_WAIT_HOURS))
            ->update(['pending_key' => null]);

        return $expired;
    }

    /**
     * Réponse connect-direct.
     *
     * @return array<string, mixed>
     */
    public function present(ConnectRequest $request): array
    {
        $company = Company::find($request->company_id);
        $status = $request->directStatus();

        return [
            'request_id' => $request->request_id,
            'status' => $status,
            'company' => ['name' => $company->name, 'slug' => $company->slug, 'url' => $company->url()],
            'connection' => $this->connection($request),
            'decide_before' => $request->decide_before?->toIso8601ZuluString(),
            'reason' => $request->decline_reason,
            'message' => match ($status) {
                'connected' => "Compte ouvert chez {$company->name}.",
                'pending' => "Demande envoyée : {$company->name} doit ouvrir votre compte.",
                'confirmation' => "Vous avez déjà un compte chez {$company->name} : confirmez sur WhatsApp.",
                'declined' => "{$company->name} n'a pas ouvert votre compte.",
                'expired' => "{$company->name} n'a pas répondu à temps.",
                default => 'Demande annulée.',
            },
        ];
    }

    /**
     * Clé de la connexion, tant qu'elle n'a pas été remise (puis null).
     *
     * @return array{api_key: string, scopes: list<string>, merchant: array{id: int, business_name: string}}|null
     */
    public function connection(ConnectRequest $request): ?array
    {
        if ($request->status !== ConnectRequest::ACCEPTED || ! is_string($request->pending_key) || $request->pending_key === '') {
            return null;
        }
        $merchant = Merchant::withoutGlobalScopes()->find($request->merchant_id);

        return [
            'api_key' => $request->pending_key,
            'scopes' => config('connect.clients.'.self::CLIENT.'.scopes'),
            'merchant' => ['id' => $merchant->id, 'business_name' => $merchant->business_name],
        ];
    }

    /**
     * Corps connect-decision envoyé à Jibiat Marchand.
     *
     * @return array<string, mixed>
     */
    public function decision(ConnectRequest $request): array
    {
        $company = Company::find($request->company_id);

        return [
            'request_id' => $request->request_id,
            'status' => match ($request->status) {
                ConnectRequest::ACCEPTED => 'connected',
                ConnectRequest::EXPIRED => 'expired',
                default => 'declined',
            },
            'company' => ['name' => $company->name, 'slug' => $company->slug, 'url' => $company->url()],
            'connection' => $this->connection($request),
            'reason' => $request->decline_reason,
            'decided_at' => ($request->decided_at ?? now())->toIso8601ZuluString(),
        ];
    }

    private function notifyStaff(Company $company, object $notification): void
    {
        $staff = User::forCompany($company->id)->where('status', 'active')->whereNull('merchant_id')
            ->permission(Permission::MerchantsManage->value)->get();
        if ($staff->isNotEmpty()) {
            Notification::send($staff, $notification);
        }
    }
}
