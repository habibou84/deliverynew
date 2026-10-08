<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WhatsAppTemplate;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OutboundMessageResource;
use App\Models\Company;
use App\Models\MetaTemplate;
use App\Models\OutboundMessage;
use App\Models\WhatsAppAccount;
use App\Rules\PhoneNumber;
use App\Services\Messaging\Gateways\WhatsAppGateway;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\Messenger;
use App\Services\WhatsApp\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Paramètres WhatsApp de l'entreprise : numéro (API Cloud de Meta), options
 * d'envoi, modèles à faire approuver, message de test.
 */
class WhatsAppSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings($this->company($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->company($request);

        $data = $request->validate([
            'notify_recipients' => ['sometimes', 'boolean'],
            'sms_fallback' => ['sometimes', 'boolean'],
            'whatsapp_orders' => ['sometimes', 'boolean'],
            'account' => ['sometimes', 'array'],
            'account.display_phone' => ['nullable', 'string', new PhoneNumber],
            'account.phone_number_id' => ['nullable', 'string', 'max:50', 'regex:/^\d+$/'],
            'account.waba_id' => ['nullable', 'string', 'max:50', 'regex:/^\d+$/'],
            // Laisser vide pour conserver le jeton enregistré
            'account.access_token' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'account.phone_number_id' => 'identifiant du numéro',
            'account.waba_id' => 'identifiant du compte WhatsApp Business',
            'account.access_token' => 'jeton d\'accès',
        ]);

        DB::transaction(function () use ($company, $data) {
            $company->update(array_intersect_key($data, array_flip(['notify_recipients', 'sms_fallback', 'whatsapp_orders'])));

            if (isset($data['account'])) {
                $account = WhatsAppAccount::forCompanyId($company->id) ?? new WhatsAppAccount([
                    'company_id' => $company->id,
                    'owner_type' => 'company',
                ]);

                $fields = array_intersect_key($data['account'], array_flip(['display_phone', 'phone_number_id', 'waba_id']));
                $account->fill($fields);
                if (filled($data['account']['access_token'] ?? null)) {
                    $account->access_token = $data['account']['access_token'];
                }
                $account->status = $account->isConfigured() ? 'connected' : 'pending';
                $account->save();
            }
        });

        return response()->json(['data' => $this->settings($company->fresh())]);
    }

    /**
     * Message de test « hello_world » (modèle fourni par Meta, sans approbation).
     */
    public function test(Request $request, Messenger $messenger): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate(['to' => ['required', 'string', new PhoneNumber]]);

        $message = $messenger->test($company->id, $data['to']);

        return response()->json(['data' => new OutboundMessageResource($message->fresh())], 201);
    }

    /**
     * Simulateur : écrit au bot comme le ferait un marchand depuis son téléphone,
     * sans compte Meta. Renvoie les réponses du bot.
     */
    public function simulate(Request $request, Conversation $conversation): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'from' => ['required', 'string', new PhoneNumber],
            'text' => ['nullable', 'string', 'max:4000', 'required_without:button_id'],
            'button_id' => ['nullable', 'string', 'max:100'],
            'button_title' => ['nullable', 'string', 'max:100'],
        ]);

        $lastId = (int) OutboundMessage::max('id');

        $inbound = $conversation->handle(
            $company,
            $data['from'],
            isset($data['button_id']) ? 'button' : 'text',
            $data['button_title'] ?? $data['text'] ?? null,
            $data['button_id'] ?? null,
        );

        $replies = OutboundMessage::where('id', '>', $lastId)->where('to', $inbound->from_phone)->orderBy('id')->get()
            ->map(fn (OutboundMessage $m) => [
                'id' => $m->id,
                'text' => $m->body,
                'buttons' => collect($m->payload['buttons'] ?? [])->map(fn ($title, $id) => ['id' => $id, 'title' => $title])->values(),
            ]);

        return response()->json(['data' => [
            'merchant_id' => $inbound->merchant_id,
            'order_id' => $inbound->order_id,
            'replies' => $replies,
        ]]);
    }

    /**
     * Récupère chez Meta l'état d'approbation des modèles.
     */
    public function syncTemplates(Request $request, WhatsAppGateway $gateway): JsonResponse
    {
        $company = $this->company($request);
        $account = WhatsAppAccount::forCompanyId($company->id);

        try {
            $remote = $gateway->templates($account);
        } catch (MessagingException $e) {
            abort(422, $e->getMessage());
        }

        $account ??= WhatsAppAccount::create(['company_id' => $company->id, 'owner_type' => 'company']);

        foreach ($remote as $template) {
            $account->templates()->updateOrCreate(
                ['name' => $template['name'], 'language' => $template['language']],
                [
                    'status' => $template['status'],
                    'category' => $template['category'],
                    'rejected_reason' => $template['rejected_reason'],
                    'synced_at' => now(),
                ],
            );
        }

        return response()->json(['data' => $this->settings($company->fresh())]);
    }

    private function company(Request $request): Company
    {
        $company = $request->user()->company;
        abort_if($company === null, 422, 'Choisissez une entreprise.');

        return $company;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(Company $company): array
    {
        $account = WhatsAppAccount::forCompanyId($company->id);
        $statuses = $account
            ? MetaTemplate::where('whatsapp_account_id', $account->id)->where('language', WhatsAppTemplate::LANGUAGE)->get()->keyBy('name')
            : collect();

        return [
            'driver' => config('messaging.whatsapp.driver'),
            'sms_driver' => config('messaging.sms.driver'),
            'webhook_url' => route('webhooks.whatsapp.handle'),
            'webhook_ready' => filled(config('messaging.whatsapp.app_secret')) && filled(config('messaging.whatsapp.verify_token')),
            'notify_recipients' => $company->notify_recipients,
            'sms_fallback' => $company->sms_fallback,
            'whatsapp_orders' => $company->whatsapp_orders,
            'ai_parser' => filled(config('messaging.ai.api_key')),
            'account' => [
                'display_phone' => $account?->display_phone,
                'phone_number_id' => $account?->phone_number_id,
                'waba_id' => $account?->waba_id,
                'has_access_token' => filled($account?->access_token),
                'status' => $account->status ?? 'pending',
            ],
            'templates' => array_map(fn (WhatsAppTemplate $t) => [
                'name' => $t->value,
                'language' => WhatsAppTemplate::LANGUAGE,
                'category' => 'utility',
                'description' => $t->description(),
                'body' => $t->body(),
                'example' => $t->example(),
                'status' => $statuses[$t->value]->status ?? 'unknown',
                'rejected_reason' => $statuses[$t->value]->rejected_reason ?? null,
                'synced_at' => $statuses[$t->value]->synced_at ?? null,
            ], WhatsAppTemplate::cases()),
        ];
    }
}
