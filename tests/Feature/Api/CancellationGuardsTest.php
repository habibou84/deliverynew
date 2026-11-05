<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\ApiKey;
use App\Models\CancellationRequest;
use App\Models\Order;
use App\Notifications\OrderAlert;
use App\Services\Orders\Cancellations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Jibiat\Socle\Contract\Contract;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Garde-fous des annulations : motif obligatoire, limite du jour de l'équipe puis validation d'un administrateur,
 * point des annulations, alertes, qui a annulé et pourquoi pour l'e-commerçant (contrat 1.9).
 */
class CancellationGuardsTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        $this->buildWorld();
    }

    /** Alertes « kind » reçues par l'administrateur */
    private function alerts(string $kind): int
    {
        return Notification::sent($this->admin, OrderAlert::class)->filter(fn (OrderAlert $n) => $n->kind === $kind)->count();
    }

    private function cancel(Order $order, array $data = [])
    {
        return $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'cancelled', ...$data]);
    }

    public function test_a_reason_is_required_and_the_merchant_sees_who_and_why(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->dispatcher);

        $this->cancel($order)->assertUnprocessable()->assertJsonValidationErrors('cancel_reason_code');
        $this->cancel($order, ['cancel_reason_code' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('cancel_reason');
        // Un motif de l'e-commerçant n'est pas un motif de l'entreprise
        $this->cancel($order, ['cancel_reason_code' => 'out_of_stock'])->assertUnprocessable();

        $this->cancel($order, ['cancel_reason_code' => 'recipient_unreachable', 'cancel_reason' => 'Appelé 3 fois'])->assertOk()
            ->assertJsonPath('data.cancellation.by', 'company')
            ->assertJsonPath('data.cancellation.reason', 'recipient_unreachable')
            ->assertJsonPath('data.cancellation.reason_label', 'Client injoignable avant le ramassage')
            ->assertJsonPath('data.cancellation.note', 'Appelé 3 fois');

        $order->refresh();
        $this->assertSame([$this->dispatcher->id, 'company'], [$order->cancelled_by_id, $order->cancelled_by_type]);
        $this->assertSame('Client injoignable avant le ramassage · Appelé 3 fois', $order->events()->latest('id')->first()->note);
        // L'e-commerçant est prévenu avec le nom de la personne
        Notification::assertSentTo($this->merchantUser, OrderAlert::class,
            fn (OrderAlert $n) => $n->kind === 'cancelled' && str_contains($n->body, 'par '.strtok($this->dispatcher->name, ' ')));
    }

    public function test_beyond_the_daily_limit_an_admin_validates(): void
    {
        $this->company->forceFill(['cancel_rules' => [...Cancellations::DEFAULT_RULES, 'staff_daily_limit' => 1]])->save();
        [$first, $second, $third] = [$this->createOrder(), $this->createOrder(), $this->createOrder()];
        Sanctum::actingAs($this->dispatcher);

        $this->getJson('/api/v1/cancellations/quota')->assertOk()->assertJsonPath('data.limit', 1)->assertJsonPath('data.needs_approval', false);
        $this->cancel($first, ['cancel_reason_code' => 'duplicate'])->assertOk();
        $this->getJson('/api/v1/cancellations/quota')->assertJsonPath('data.count', 1)->assertJsonPath('data.needs_approval', true);

        // Au-delà : demande, la course reste active, les administrateurs sont prévenus
        $this->cancel($second, ['cancel_reason_code' => 'out_of_area'])->assertStatus(202);
        $this->cancel($third, ['cancel_reason_code' => 'merchant_request'])->assertStatus(202);
        $this->assertSame(OrderStatus::Pending, $second->fresh()->status);
        $this->cancel($second, ['cancel_reason_code' => 'out_of_area'])->assertUnprocessable();
        $this->assertSame(2, $this->alerts('cancellation_request'));

        // Le dispatch ne valide pas lui-même
        $request = CancellationRequest::where('order_id', $second->id)->sole();
        $this->postJson("/api/v1/cancellation-requests/{$request->id}/decision", ['approve' => true])->assertUnprocessable();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/cancellations')->assertOk()->assertJsonCount(2, 'data.requests')->assertJsonPath('data.is_admin', true);
        // Tableau de bord « À faire maintenant » : demandes à valider
        $this->getJson('/api/v1/orders/counts')->assertOk()->assertJsonPath('data.cancellation_requests', 2);
        $this->postJson("/api/v1/cancellation-requests/{$request->id}/decision", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $second->refresh();
        $this->assertSame(OrderStatus::Cancelled, $second->status);
        $this->assertSame($this->dispatcher->id, $second->cancelled_by_id);
        $this->assertStringContainsString('validée par', $second->events()->latest('id')->first()->note);

        // Refusée : la course continue ; course déjà ramassée : sans objet
        $other = CancellationRequest::where('order_id', $third->id)->sole();
        $this->postJson("/api/v1/cancellation-requests/{$other->id}/decision", ['approve' => false, 'note' => 'On la livre'])->assertOk()->assertJsonPath('data.status', 'refused');
        $this->assertSame(OrderStatus::Pending, $third->fresh()->status);
        $this->postJson("/api/v1/cancellation-requests/{$other->id}/decision", ['approve' => true])->assertUnprocessable();

        // Un administrateur n'a pas de limite
        $this->cancel($this->createOrder(), ['cancel_reason_code' => 'duplicate'])->assertOk();
        $this->cancel($this->createOrder(), ['cancel_reason_code' => 'duplicate'])->assertOk();
    }

    public function test_merchants_give_their_own_reasons_through_the_app_and_the_api(): void
    {
        $order = $this->createOrder();
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/cancellations/quota')->assertOk()->assertJsonPath('data.reasons.0.code', 'customer_cancelled')->assertJsonPath('data.limit', null);
        $this->cancel($order, ['cancel_reason_code' => 'duplicate'])->assertUnprocessable();
        $this->cancel($order, ['cancel_reason_code' => 'self_delivery'])->assertOk()
            ->assertJsonPath('data.cancellation.by', 'merchant')->assertJsonPath('data.cancellation.role', null);

        // API publique : un texte seul vaut « Autre » ; la course annulée respecte le contrat 1.9
        [, $key] = ApiKey::issue($this->merchant, 'Boutique', ['orders:read', 'orders:write'], $this->merchantUser);
        $code = $this->createOrder()->tracking_code;
        $response = $this->withToken($key)->postJson("/api/public/v1/orders/{$code}/cancel", ['reason' => 'Le client a changé d\'avis'])->assertOk()
            ->assertJsonPath('data.cancellation.reason', 'other')->assertJsonPath('data.cancellation.note', 'Le client a changé d\'avis');
        $this->assertSame([], Contract::validate('order-response', $response->json()));
        $code = $this->createOrder()->tracking_code;
        $this->withToken($key)->postJson("/api/public/v1/orders/{$code}/cancel")->assertUnprocessable();
        $this->withToken($key)->postJson("/api/public/v1/orders/{$code}/cancel", ['reason_code' => 'out_of_stock'])->assertOk()
            ->assertJsonPath('data.cancellation.reason_label', 'Produit en rupture');
    }

    public function test_the_report_counts_by_merchant_staff_and_reason_and_rules_are_for_admins(): void
    {
        Sanctum::actingAs($this->merchantUser);
        $this->cancel($this->createOrder(), ['cancel_reason_code' => 'customer_cancelled'])->assertOk();
        $this->cancel($this->createOrder(), ['cancel_reason_code' => 'customer_cancelled'])->assertOk();
        $this->createOrder();
        Sanctum::actingAs($this->dispatcher);
        $this->cancel($this->createOrder(), ['cancel_reason_code' => 'duplicate'])->assertOk();
        $this->postJson('/api/v1/orders/'.$this->createOrder()->id.'/status', ['status' => 'rejected', 'cancel_reason_code' => 'out_of_area'])->assertOk();

        $data = $this->getJson('/api/v1/cancellations')->assertOk()->json('data');
        $this->assertSame([4, 2, 2, 1], [$data['totals']['cancelled'], $data['totals']['by_merchants'], $data['totals']['by_company'], $data['totals']['rejected']]);
        $this->assertSame([2, 'Le client a annulé sa commande'], [$data['merchants'][0]['cancelled'], $data['merchants'][0]['top_reason']]);
        $this->assertSame([$this->dispatcher->name, 1, 1], [$data['staff'][0]['name'], $data['staff'][0]['cancelled'], $data['staff'][0]['rejected']]);
        $this->assertSame('Le client a annulé sa commande', $data['reasons'][0]['label']);

        $rules = ['staff_daily_limit' => 3, 'hourly_alert' => null, 'merchant_rate_alert' => 20, 'weekly_report' => false];
        $this->putJson('/api/v1/cancellations/rules', $rules)->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/cancellations/rules', $rules)->assertOk()->assertJsonPath('data.staff_daily_limit', 3);
        $this->assertNull(app(Cancellations::class)->weeklyLine($this->company->fresh(), now()->subWeek(), now()));

        // Réservé à l'équipe
        Sanctum::actingAs($this->merchantUser);
        $this->getJson('/api/v1/cancellations')->assertForbidden();
    }

    public function test_admins_are_alerted_of_bursts_and_of_merchants_who_cancel_too_much(): void
    {
        $this->company->forceFill(['cancel_rules' => [...Cancellations::DEFAULT_RULES, 'staff_daily_limit' => null, 'hourly_alert' => 2, 'merchant_rate_alert' => 20]])->save();
        Sanctum::actingAs($this->dispatcher);
        foreach (range(1, 4) as $i) {
            $this->cancel($this->createOrder(), ['cancel_reason_code' => 'duplicate'])->assertOk();
        }
        // Une seule alerte par heure
        $this->assertSame(1, $this->alerts('cancellations_burst'));

        Sanctum::actingAs($this->merchantUser);
        foreach (range(1, 10) as $i) {
            $order = $this->createOrder();
            if ($i <= 4) {
                $this->cancel($order, ['cancel_reason_code' => 'customer_cancelled'])->assertOk();
            }
        }
        $service = app(Cancellations::class);
        $this->assertSame(1, $service->alertMerchantRates($this->company->fresh()));
        $this->assertSame(0, $service->alertMerchantRates($this->company->fresh()), 'Une fois par jour');
        $this->assertSame(1, $this->alerts('cancellations_merchants'));
        $this->assertStringContainsString('annulation(s)', $service->weeklyLine($this->company->fresh(), now()->subWeek(), now()));
    }
}
