<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\InboundMessage;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\InboxContacts;
use App\Services\Messaging\Messenger;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Messages › Reçus : ce que les clients, les e-commerçants et les livreurs écrivent au WhatsApp de
 * l'entreprise, rangé par conversation (un numéro), avec les messages envoyés à ce numéro. L'équipe lit
 * et répond. Avec la connexion officielle Meta, une réponse libre n'est permise que dans les 24 h qui
 * suivent le dernier message reçu ; avec le QR code, toujours.
 */
class InboxController extends Controller
{
    public const WINDOW_HOURS = 24;

    public function __construct(private readonly InboxContacts $contacts) {}

    /** Conversations, la plus récente en premier */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::OrdersDispatch->value);
        $request->validate([
            'filter' => ['nullable', Rule::in(['unread', InboxContacts::CLIENT, InboxContacts::MERCHANT, InboxContacts::COURIER, InboxContacts::UNKNOWN])],
            'search' => ['nullable', 'string', 'max:50'],
        ]);
        $companyId = $request->user()->company_id;

        $rows = InboundMessage::query()
            ->where('simulated', false)
            ->when($request->input('filter') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->selectRaw('from_phone, MAX(id) AS last_id, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread')
            ->groupBy('from_phone')
            ->orderByDesc('last_id')
            ->limit(300)
            ->get();

        $last = InboundMessage::whereIn('id', $rows->pluck('last_id'))->get()->keyBy('id');
        $contacts = $this->contacts->identify($companyId, $rows->pluck('from_phone')->all());

        $search = $request->filled('search') ? mb_strtolower(preg_replace('/\s+/', '', (string) $request->input('search'))) : null;
        $kind = in_array($request->input('filter'), [null, 'unread'], true) ? null : $request->input('filter');

        $data = $rows->map(function ($row) use ($last, $contacts) {
            $message = $last[$row->last_id];

            return [
                'contact' => $contacts[$row->from_phone],
                'last' => ['body' => $this->preview($message), 'at' => $message->created_at],
                'unread' => (int) $row->unread,
            ];
        })->filter(fn ($c) => $kind === null || $c['contact']['kind'] === $kind)
            ->filter(fn ($c) => $search === null || str_contains(mb_strtolower(str_replace(' ', '', implode(' ', array_filter([
                $c['contact']['name'], $c['contact']['phone'], $c['contact']['order']['tracking_code'] ?? null,
            ])))), $search))
            ->values()
            ->take(100);

        return response()->json(['data' => $data, 'meta' => ['unread' => $this->unreadCount()]]);
    }

    /** Nombre de conversations non lues (compteur du menu) */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize(Permission::OrdersDispatch->value);

        return response()->json(['data' => ['unread' => $this->unreadCount()]]);
    }

    /** Une conversation : messages reçus et envoyés à ce numéro ; marquée lue */
    public function show(Request $request, string $phone): JsonResponse
    {
        Gate::authorize(Permission::OrdersDispatch->value);
        $phone = $this->phone($phone);

        InboundMessage::where('from_phone', $phone)->where('simulated', false)->whereNull('read_at')
            ->update(['read_at' => now(), 'read_by' => $request->user()->id]);

        return response()->json(['data' => $this->conversation($request->user()->company_id, $phone)]);
    }

    /** Réponse de l'équipe */
    public function reply(Request $request, string $phone, Messenger $messenger): JsonResponse
    {
        Gate::authorize(Permission::OrdersDispatch->value);
        $data = $request->validate(['text' => ['required', 'string', 'max:1000']], [], ['text' => 'réponse']);
        $phone = $this->phone($phone);
        $companyId = $request->user()->company_id;

        $window = $this->window($companyId, $phone);
        if (! $window['open']) {
            throw new BusinessRuleException($window['reason'], 'text');
        }

        $contact = $this->contacts->identify($companyId, [$phone])[$phone];
        $messenger->reply($companyId, $phone, trim($data['text']), [], [
            'recipient_type' => $contact['kind'] === InboxContacts::CLIENT ? 'recipient' : $contact['kind'],
            'event' => 'inbox_reply',
            'order_id' => $contact['order']['id'] ?? null,
            'merchant_id' => $contact['merchant_id'],
        ]);

        return response()->json(['data' => $this->conversation($companyId, $phone)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function conversation(int $companyId, string $phone): array
    {
        $inbound = InboundMessage::where('from_phone', $phone)->where('simulated', false)->latest('id')->limit(100)->get()
            ->map(fn (InboundMessage $m) => [
                'id' => "in-{$m->id}", 'direction' => 'in', 'body' => $this->preview($m), 'at' => $m->created_at,
                'order' => $m->order_id,
            ]);
        $outbound = OutboundMessage::where('to', $phone)->latest('id')->limit(100)->get()
            ->map(fn (OutboundMessage $m) => [
                'id' => "out-{$m->id}", 'direction' => 'out', 'body' => $m->body, 'at' => $m->created_at,
                'channel' => $m->channel->value, 'status' => $m->status->value, 'status_label' => $m->status->label(),
                'automatic' => $m->event !== 'inbox_reply',
                'template' => $m->template_name,
            ]);

        return [
            'contact' => $this->contacts->identify($companyId, [$phone])[$phone],
            'messages' => $inbound->concat($outbound)->sortBy(fn ($m) => [$m['at']?->getTimestamp(), $m['id']])->values(),
            'window' => $this->window($companyId, $phone),
        ];
    }

    /**
     * Réponse libre possible ? Meta : 24 h après le dernier message reçu. QR code : toujours.
     *
     * @return array{open: bool, until: ?Carbon, reason: ?string}
     */
    private function window(int $companyId, string $phone): array
    {
        $account = WhatsAppAccount::forCompanyId($companyId);
        if ($account === null || ! $account->isConfigured()) {
            return ['open' => false, 'until' => null, 'reason' => 'WhatsApp n\'est pas connecté : connectez-le dans Messages › Connexion WhatsApp.'];
        }

        if ($account->isQr()) {
            return ['open' => true, 'until' => null, 'reason' => null];
        }

        $lastIn = InboundMessage::where('from_phone', $phone)->where('simulated', false)->latest('id')->value('created_at');
        $until = $lastIn ? Carbon::parse($lastIn)->addHours(self::WINDOW_HOURS) : null;
        if ($until === null || $until->isPast()) {
            return ['open' => false, 'until' => $until, 'reason' => 'Plus de 24 h depuis son dernier message : Meta n\'autore plus de réponse libre. Seuls les messages prédéfinis partent, jusqu\'à ce que la personne écrive de nouveau.'];
        }

        return ['open' => true, 'until' => $until, 'reason' => null];
    }

    private function unreadCount(): int
    {
        return InboundMessage::where('simulated', false)->whereNull('read_at')->distinct()->count('from_phone');
    }

    private function preview(InboundMessage $message): string
    {
        return match (true) {
            filled($message->body) => (string) $message->body,
            $message->type === 'image' => '[Photo]',
            $message->type === 'audio' => '[Message vocal]',
            $message->type === 'location' => '[Position]',
            default => '[Message sans texte]',
        };
    }

    private function phone(string $phone): string
    {
        $normalized = PhoneNumber::normalize($phone);
        abort_if($normalized === null, 404, 'Conversation introuvable.');

        return $normalized;
    }
}
