<?php

namespace Tests\Feature\Api;

use App\Models\CourierRating;
use App\Models\CourierReview;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\CourierRatingAlert;
use App\Services\WhatsApp\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

class CourierRatingTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    private const RECIPIENT = '+2250707070707';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * Course livrée par le livreur B.
     */
    private function delivered(): Order
    {
        $order = $this->createOrder(['items_amount' => 12000]);
        $this->as($this->dispatcher);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'pickup', 'courier_id' => $this->courierA->id]);
        $this->postJson("/api/v1/orders/{$order->id}/assign", ['type' => 'delivery', 'courier_id' => $this->courierB->id]);
        $this->as($this->courierA->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->as($this->courierB->user)->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered', 'delivery_code' => $order->fresh()->delivery_code])->assertOk();

        return $order->fresh();
    }

    /**
     * Le destinataire écrit sur WhatsApp ; renvoie les réponses envoyées.
     *
     * @return list<string>
     */
    private function say(string $text): array
    {
        $lastId = (int) OutboundMessage::max('id');
        app(Conversation::class)->handle($this->company->fresh(), self::RECIPIENT, 'text', $text);

        return OutboundMessage::where('id', '>', $lastId)->orderBy('id')->pluck('body')->all();
    }

    public function test_recipient_is_asked_after_delivery_and_answers_on_whatsapp(): void
    {
        Notification::fake();
        $order = $this->delivered();

        $ask = OutboundMessage::where('template_name', 'avis_livraison')->sole();
        $this->assertSame(self::RECIPIENT, $ask->to);
        $this->assertSame($order->id, $ask->order_id);
        $this->assertNotNull($order->rating_token);
        $this->assertStringContainsString('/avis/'.$order->rating_token, $ask->body);
        $this->assertStringContainsString('1 (mauvais) à 5', $ask->body);

        $replies = $this->say('5');
        $this->assertCount(1, $replies);
        $this->assertStringContainsString('Merci', $replies[0]);

        $rating = CourierRating::sole();
        $this->assertSame(5, $rating->score);
        $this->assertSame($this->courierB->id, $rating->courier_id);
        $this->assertSame('whatsapp', $rating->channel);
        $this->assertFalse($rating->flagged);

        // Le mot qui suit complète l'avis
        $this->assertSame(["C'est noté, merci 🙏"], $this->say('Très rapide et poli'));
        $this->assertSame('Très rapide et poli', $rating->fresh()->comment);

        // Ensuite, les messages ne sont plus des avis
        $this->say('Bonjour');
        $this->assertSame('Très rapide et poli', $rating->fresh()->comment);
        $this->assertSame(1, CourierRating::count());
        Notification::assertSentTimes(CourierRatingAlert::class, 0);
    }

    public function test_one_star_with_a_comment_alerts_the_managers(): void
    {
        Notification::fake();
        $this->delivered();

        $this->say('1 il m\'a demandé 1000 F de plus');

        $rating = CourierRating::sole();
        $this->assertSame(1, $rating->score);
        $this->assertSame("il m'a demandé 1000 F de plus", $rating->comment);
        $this->assertTrue($rating->flagged);
        Notification::assertSentTo([$this->admin, $this->dispatcher], CourierRatingAlert::class);
        Notification::assertNotSentTo($this->courierB->user, CourierRatingAlert::class);
    }

    public function test_rating_page_shows_the_delivery_and_accepts_one_rating(): void
    {
        Notification::fake();
        $order = $this->delivered();

        $this->getJson('/api/v1/ratings/'.$order->rating_token)->assertOk()
            ->assertJsonPath('data.rated', false)
            ->assertJsonPath('data.expired', false)
            ->assertJsonPath('data.merchant_name', 'Boutique Test')
            ->assertJsonPath('data.courier_first_name', strtok($this->courierB->user->name, ' '))
            ->assertJsonMissingPath('data.recipient_phone');

        $this->postJson('/api/v1/ratings/'.$order->rating_token, ['score' => 2, 'tags' => ['late', 'asked_money'], 'comment' => 'Trop tard'])
            ->assertCreated()->assertJsonPath('data.rated', true);

        $rating = CourierRating::sole();
        $this->assertSame(['late', 'asked_money'], $rating->tags);
        $this->assertSame('web', $rating->channel);
        $this->assertTrue($rating->flagged);
        Notification::assertSentTo($this->dispatcher, CourierRatingAlert::class);

        $this->postJson('/api/v1/ratings/'.$order->rating_token, ['score' => 5])->assertUnprocessable();
        $this->getJson('/api/v1/ratings/inconnu')->assertNotFound();
    }

    public function test_rating_is_closed_after_seven_days_and_can_be_turned_off(): void
    {
        $order = $this->delivered();
        $order->forceFill(['delivered_at' => now()->subDays(8)])->save();

        $this->getJson('/api/v1/ratings/'.$order->rating_token)->assertJsonPath('data.expired', true);
        $this->postJson('/api/v1/ratings/'.$order->rating_token, ['score' => 4])->assertUnprocessable();

        $this->company->update(['ask_recipient_rating' => false]);
        $this->delivered();
        $this->assertSame(1, OutboundMessage::where('template_name', 'avis_livraison')->count());
    }

    public function test_managers_see_the_ranking_review_and_cancel(): void
    {
        Notification::fake();
        $order = $this->delivered();
        $this->postJson('/api/v1/ratings/'.$order->rating_token, ['score' => 1, 'tags' => ['rude']])->assertCreated();
        $rating = CourierRating::sole();

        $this->as($this->dispatcher)->getJson('/api/v1/courier-ratings')->assertOk()
            ->assertJsonPath('data.summary.to_handle', 1)
            ->assertJsonPath('data.to_handle.0.id', $rating->id)
            ->assertJsonPath('data.to_handle.0.courier_id', $this->courierB->id);

        // Évaluation du mois
        $this->postJson("/api/v1/couriers/{$this->courierB->id}/reviews", [
            'period' => now()->format('Y-m'),
            'criteria' => ['punctuality' => 3, 'appearance' => 4, 'cash' => 5, 'instructions' => 3, 'behaviour' => 5],
            'comment' => 'Appeler les clients avant',
        ])->assertCreated()->assertJsonPath('data.score', 4);
        $this->assertSame(1, CourierReview::count());

        // Une note de client (1) et une évaluation (4) : 40/30 puis lissage vers 4 avec 2 avis
        $detail = $this->getJson("/api/v1/couriers/{$this->courierB->id}/ratings")->assertOk();
        $detail->assertJsonPath('data.sources.recipient.score', 1)
            ->assertJsonPath('data.sources.manager.score', 4)
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.tags.0.code', 'rude')
            ->assertJsonPath('data.reviews.0.comment', 'Appeler les clients avant');
        $raw = (0.4 * 1 + 0.3 * 4) / 0.7;
        $this->assertEqualsWithDelta(round((5 * 4 + 2 * $raw) / 7, 1), $detail->json('data.score'), 0.001);

        // Avis abusif annulé : il ne compte plus
        $this->postJson("/api/v1/courier-ratings/{$rating->id}/cancel", ['reason' => 'Client absent, pas la faute du livreur'])->assertOk();
        $this->getJson("/api/v1/couriers/{$this->courierB->id}/ratings")
            ->assertJsonPath('data.sources.recipient.count', 0)
            ->assertJsonPath('data.score', 4);
        $this->getJson('/api/v1/courier-ratings')->assertJsonPath('data.summary.to_handle', 0);

        // Un livreur ne voit pas les notes
        $this->as($this->courierB->user)->getJson('/api/v1/courier-ratings')->assertForbidden();
    }

    public function test_merchant_rates_the_courier_from_the_app(): void
    {
        Notification::fake();
        $order = $this->delivered();

        // Avant de noter : la fiche le propose, la note du client y apparaît
        $this->postJson('/api/v1/ratings/'.$order->rating_token, ['score' => 5, 'comment' => 'Parfait'])->assertCreated();
        $this->as($this->merchantUser)->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.ratings.can_rate', true)
            ->assertJsonPath('data.ratings.merchant', null)
            ->assertJsonPath('data.ratings.recipient.score', 5)
            ->assertJsonPath('data.ratings.recipient.comment', 'Parfait')
            ->assertJsonMissingPath('data.ratings.recipient.phone');

        $this->postJson("/api/v1/orders/{$order->id}/rating", ['score' => 2, 'tags' => ['pickup_late', 'customer_mistreated'], 'comment' => 'Il a crié sur ma cliente'])->assertOk()
            ->assertJsonPath('data.ratings.can_rate', false)
            ->assertJsonPath('data.ratings.merchant.score', 2);

        $rating = CourierRating::where('source', 'merchant')->sole();
        $this->assertSame([$this->courierB->id, 'app', $this->merchantUser->id], [$rating->courier_id, $rating->channel, $rating->rater_user_id]);
        $this->assertTrue($rating->flagged);
        Notification::assertSentTo($this->dispatcher, CourierRatingAlert::class);
        $this->postJson("/api/v1/orders/{$order->id}/rating", ['score' => 5])->assertUnprocessable();

        // La note de l'e-commerçant compte dans la fiche du livreur
        $this->as($this->dispatcher)->getJson("/api/v1/couriers/{$this->courierB->id}/ratings")
            ->assertJsonPath('data.sources.merchant.score', 2)
            ->assertJsonPath('data.sources.recipient.score', 5);

        // Ni un autre e-commerçant, ni l'équipe ne notent à sa place
        $this->as($this->dispatcher)->postJson("/api/v1/orders/{$order->id}/rating", ['score' => 5])->assertForbidden();
    }

    public function test_merchant_cannot_rate_an_undelivered_order(): void
    {
        $order = $this->createOrder();
        $this->as($this->merchantUser)->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.ratings.can_rate', false);
        $this->postJson("/api/v1/orders/{$order->id}/rating", ['score' => 4])->assertUnprocessable();
    }

    public function test_settings_are_managed_by_the_admin(): void
    {
        $this->as($this->admin)->putJson('/api/v1/courier-ratings/settings', ['ask_recipient_rating' => false, 'rating_alert_threshold' => 4])
            ->assertOk()->assertJsonPath('data.ask_recipient_rating', false)->assertJsonPath('data.rating_alert_threshold', 4);
        $this->as($this->dispatcher)->putJson('/api/v1/courier-ratings/settings', ['rating_alert_threshold' => 2])->assertForbidden();
    }
}
