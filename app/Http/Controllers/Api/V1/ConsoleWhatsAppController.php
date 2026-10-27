<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageChannel;
use App\Enums\PlatformTemplate;
use App\Http\Controllers\Controller;
use App\Models\OutboundMessage;
use App\Models\PhoneVerification;
use App\Models\PlatformConversation;
use App\Models\PlatformMessage;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Rules\PhoneNumber as PhoneNumberRule;
use App\Services\Messaging\Gateways\MetaCloudGateway;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppBridge;
use App\Services\Messaging\WhatsAppConnection;
use App\Services\Platform\PlatformWhatsApp;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Console › WhatsApp : relier le numéro de Jibiat (connexion officielle de Meta saisie à la main, ou QR code), lire les conversations et y répondre ; SMS du mois
 * (payants) et interrupteur du SMS en secours.
 */
class ConsoleWhatsAppController extends Controller
{
    public function __construct(
        private readonly PlatformWhatsApp $platform,
        private readonly WhatsAppBridge $bridge,
        private readonly WhatsAppConnection $connection,
    ) {}

    /** État de la connexion (QR code à scanner tant qu'elle n'est pas faite) et messages non lus */
    public function show(): JsonResponse
    {
        $live = null;
        if (WhatsAppBridge::configured() && $this->platform->qrState()['status'] === 'pending') {
            try {
                $live = $this->bridge->status(PlatformWhatsApp::SESSION);
            } catch (MessagingException) {
                $live = null;
            }
        }

        return response()->json(['data' => $this->describe($live)]);
    }

    public function connect(): JsonResponse
    {
        try {
            $this->platform->markPending();
            $live = $this->bridge->start(PlatformWhatsApp::SESSION);
        } catch (MessagingException $e) {
            throw ValidationException::withMessages(['qr' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->describe($live)]);
    }

    /** SMS en secours pour tout le monde (même quand WhatsApp est possible) : à éviter, chaque SMS coûte */
    public function sms(Request $request): JsonResponse
    {
        $data = $request->validate(['fallback' => ['required', 'boolean']]);
        PlatformSetting::put('sms.fallback', $data['fallback'] ? 'on' : 'off');

        return response()->json(['data' => $this->describe(null)]);
    }

    /**
     * Connexion officielle (API Cloud de Meta) saisie à la main : identifiants du numéro et du compte WhatsApp
     * Business, jeton permanent d'un utilisateur système. Vérifiée aussitôt auprès de Meta.
     */
    public function official(Request $request): JsonResponse
    {
        $account = WhatsAppAccount::platform();
        $data = $request->validate([
            'display_phone' => ['nullable', 'string', new PhoneNumberRule],
            'phone_number_id' => ['required', 'string', 'max:50', 'regex:/^\d+$/', Rule::unique('whatsapp_accounts', 'phone_number_id')->ignore($account?->id)],
            'waba_id' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            // Laisser vide pour conserver le jeton enregistré
            'access_token' => [$account?->access_token ? 'nullable' : 'required', 'string', 'max:1000'],
        ], [], [
            'phone_number_id' => 'identifiant du numéro',
            'waba_id' => 'identifiant du compte WhatsApp Business',
            'access_token' => 'jeton d\'accès',
            'display_phone' => 'numéro',
        ]);

        $account ??= new WhatsAppAccount(['owner_type' => WhatsAppAccount::PLATFORM, 'company_id' => null]);
        $account->fill([
            'provider' => 'meta_cloud',
            'display_phone' => filled($data['display_phone'] ?? null) ? PhoneNumber::normalize($data['display_phone']) : $account->display_phone,
            'phone_number_id' => $data['phone_number_id'],
            'waba_id' => $data['waba_id'],
            'platform_token' => false,
        ]);
        if (filled($data['access_token'] ?? null)) {
            $account->access_token = trim($data['access_token']);
        }
        $account->save();
        $this->connection->connect($account);
        // Numéro vérifié : ses modèles partent tout de suite chez Meta
        if ($account->status === 'connected') {
            try {
                $this->connection->submitPlatformTemplates($account);
            } catch (MessagingException) {
                // Les modèles pourront être soumis avec le bouton de la console
            }
        }

        return response()->json(['data' => $this->describe(null)]);
    }

    /**
     * Soumet à Meta les modèles du numéro officiel qui ne sont ni approuvés ni en cours de validation.
     */
    public function submitTemplates(): JsonResponse
    {
        $account = WhatsAppAccount::platform();
        abort_if($account === null, 422, 'Renseignez d\'abord la connexion officielle.');

        try {
            $results = $this->connection->submitPlatformTemplates($account);
        } catch (MessagingException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $this->describe(null), 'results' => $results]);
    }

    /** État d'approbation des modèles du numéro officiel, lu chez Meta */
    public function syncTemplates(MetaCloudGateway $gateway): JsonResponse
    {
        $account = WhatsAppAccount::platform();
        abort_if($account === null, 422, 'Renseignez d\'abord la connexion officielle.');

        try {
            $remote = $gateway->templates($account);
        } catch (MessagingException $e) {
            abort(422, $e->getMessage());
        }
        $names = array_map(fn (PlatformTemplate $t) => $t->value, PlatformTemplate::cases());
        foreach ($remote as $template) {
            if (! in_array($template['name'], $names, true)) {
                continue;
            }
            $account->templates()->updateOrCreate(
                ['name' => $template['name'], 'language' => $template['language']],
                ['status' => $template['status'], 'category' => $template['category'], 'rejected_reason' => $template['rejected_reason'], 'synced_at' => now()],
            );
        }

        return response()->json(['data' => $this->describe(null)]);
    }

    /** Vérifie à nouveau la connexion officielle auprès de Meta */
    public function verifyOfficial(): JsonResponse
    {
        $account = WhatsAppAccount::platform();
        abort_if($account === null, 422, 'Renseignez d\'abord la connexion officielle.');
        $this->connection->connect($account);

        return response()->json(['data' => $this->describe(null)]);
    }

    public function removeOfficial(): JsonResponse
    {
        WhatsAppAccount::platform()?->delete();

        return response()->json(['data' => $this->describe(null)]);
    }

    public function disconnect(): JsonResponse
    {
        try {
            $this->bridge->logout(PlatformWhatsApp::SESSION);
        } catch (MessagingException) {
            // Service injoignable : la connexion est tout de même marquée coupée
        }
        $this->platform->markDisconnected();

        return response()->json(['data' => $this->describe(null)]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q'));
        $digits = preg_replace('/\D/', '', $search);
        $conversations = PlatformConversation::query()->with('company:id,name,slug')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->when($digits !== '', fn ($q) => $q->orWhere('phone', 'like', "%{$digits}%"))
                ->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereHas('company', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))))
            ->orderByDesc('last_message_at')->limit(100)->get();

        return response()->json(['data' => $conversations->map(fn (PlatformConversation $c) => $this->conversation($c))->values()]);
    }

    /** Nouvelle conversation vers un numéro (entreprise ou prospect) ; existante si le numéro a déjà écrit */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', new PhoneNumberRule]], [], ['phone' => 'numéro']);
        $phone = PhoneNumber::normalize($data['phone']);
        $conversation = PlatformConversation::query()->firstOrCreate(['phone' => $phone], [
            'company_id' => User::withoutGlobalScopes()->where('phone', $phone)->whereNull('merchant_id')->whereNotNull('company_id')->value('company_id'),
        ]);

        return response()->json(['data' => $this->conversation($conversation->load('company:id,name,slug'))], 201);
    }

    /** Fil d'une conversation (les 200 derniers messages) ; la conversation est marquée lue */
    public function messages(PlatformConversation $conversation): JsonResponse
    {
        $conversation->forceFill(['unread' => 0])->save();
        $messages = $conversation->messages()->with('user:id,name')->latest('id')->limit(200)->get()->reverse()->values();

        return response()->json(['data' => [
            'conversation' => $this->conversation($conversation->load('company:id,name,slug')),
            'messages' => $messages->map(fn (PlatformMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'body' => $m->body,
                'status' => $m->status,
                'error' => $m->error,
                'by' => $m->user?->name,
                'at' => $m->created_at,
            ]),
        ]]);
    }

    public function reply(Request $request, PlatformConversation $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']], [], ['body' => 'message']);

        try {
            $message = $this->platform->reply($conversation, trim($data['body']), $request->user());
        } catch (MessagingException $e) {
            throw ValidationException::withMessages(['body' => $e->getMessage()]);
        }

        return response()->json(['data' => ['id' => $message->id, 'status' => $message->status, 'error' => $message->error]], 201);
    }

    /**
     * @param  array{status: string, qr: ?string, phone: ?string, name: ?string}|null  $live
     * @return array<string, mixed>
     */
    private function describe(?array $live): array
    {
        $state = $this->platform->state();
        $qr = $this->platform->qrState();
        $official = WhatsAppAccount::platform();

        return [
            'available' => WhatsAppBridge::configured(),
            ...$state,
            // Connexion par QR code (secours de l'officielle)
            'qr_state' => $qr,
            'qr' => $qr['status'] === 'connected' ? null : ($live['qr'] ?? null),
            'official' => $official ? [
                'status' => $official->status,
                'live' => $official->isLive(),
                'display_phone' => $official->display_phone,
                'phone_number_id' => $official->phone_number_id,
                'waba_id' => $official->waba_id,
                'has_token' => filled($official->access_token),
                'verified_name' => $official->verified_name,
                'quality_rating' => $official->quality_rating,
                'webhook_subscribed' => $official->webhook_subscribed_at !== null,
                'error' => $official->last_error,
                'connected_at' => $official->connected_at,
            ] : null,
            // Modèles du numéro officiel : messages que Jibiat envoie en premier (au-delà des 24 heures)
            'templates' => $this->templates($official),
            'webhook_url' => route('webhooks.whatsapp.handle'),
            'unread' => (int) PlatformConversation::query()->sum('unread'),
            'sms' => [
                'month' => $this->smsCount(now()->startOfMonth(), now()->startOfMonth()->addMonth()),
                'last_month' => $this->smsCount(now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()),
                'fallback' => PlatformSetting::get('sms.fallback') === 'on',
                'ready' => ! in_array(config('messaging.sms.driver'), ['log', 'none', null], true),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(?WhatsAppAccount $official): array
    {
        $known = $official
            ? $official->templates()->where('language', PlatformTemplate::LANGUAGE)->get()->keyBy('name')
            : collect();

        return array_map(fn (PlatformTemplate $t) => [
            'name' => $t->value,
            'description' => $t->description(),
            'category' => $t->category(),
            'body' => $t->render($t->example()),
            'status' => $known[$t->value]->status ?? 'none',
            'rejected_reason' => $known[$t->value]->rejected_reason ?? null,
        ], PlatformTemplate::cases());
    }

    /** SMS partis sur la période : codes de vérification et messages envoyés par SMS (repli de WhatsApp) */
    private function smsCount(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) PhoneVerification::query()->where('sms_sent_at', '>=', $from)->where('sms_sent_at', '<', $to)->sum('sms_count')
            + OutboundMessage::withoutGlobalScopes()->where('channel', MessageChannel::Sms)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversation(PlatformConversation $c): array
    {
        return [
            'id' => $c->id,
            'phone' => $c->phone,
            'name' => $c->name,
            'company' => $c->company ? ['id' => $c->company->id, 'name' => $c->company->name] : null,
            'last_message' => $c->last_message,
            'last_message_at' => $c->last_message_at,
            'unread' => $c->unread,
        ];
    }
}
