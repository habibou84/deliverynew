<?php

namespace Tests\Feature\Api;

use App\Models\PlatformSetting;
use App\Notifications\VerificationCodeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsDeliveryWorld;
use Tests\TestCase;

/**
 * Code de vérification envoyé par e-mail (secours gratuit) quand ni WhatsApp ni SMS ne conviennent : mot de passe
 * oublié d'un compte qui a une adresse e-mail, SMS coupés sur le serveur.
 */
class VerificationEmailTest extends TestCase
{
    use BuildsDeliveryWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        // Ni WhatsApp relié, ni SMS : l'e-mail est le seul secours ; serveur d'e-mail du serveur (pas « log »)
        config(['messaging.sms.driver' => 'none', 'mail.default' => 'smtp']);
        Notification::fake();
    }

    public function test_the_code_can_be_sent_by_email_and_resets_the_password(): void
    {
        $this->merchantUser->forceFill(['email' => 'awa@example.ci'])->save();

        $start = $this->postJson('/api/v1/password/forgot', ['phone' => $this->merchantUser->phone])->assertCreated()
            ->assertJsonPath('data.whatsapp', null)
            ->assertJsonPath('data.sms.available', false)
            ->assertJsonPath('data.email.available', true)
            ->assertJsonPath('data.email.hint', 'a•••@example.ci')
            ->assertJsonPath('data.email.sent', false);
        $token = $start->json('token');

        $this->postJson("/api/v1/verifications/{$token}/email")->assertOk()->assertJsonPath('data.email.sent', true);
        $code = null;
        Notification::assertSentOnDemand(VerificationCodeMail::class, function (VerificationCodeMail $mail, array $channels, AnonymousNotifiable $to) use (&$code) {
            $code = $mail->code;

            return $to->routes['mail'] === 'awa@example.ci';
        });
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $link = (new VerificationCodeMail($code, 'x', 'y', $this->company->url('/mot-de-passe-oublie?code='.$code)))->toMail(new AnonymousNotifiable)->actionUrl;
        $this->assertStringEndsWith('/mot-de-passe-oublie?code='.$code, $link);

        // Un deuxième e-mail attend une minute
        $this->postJson("/api/v1/verifications/{$token}/email")->assertUnprocessable();

        $this->postJson("/api/v1/verifications/{$token}/code", ['code' => '000000'])->assertUnprocessable();
        $this->postJson("/api/v1/verifications/{$token}/code", ['code' => $code])->assertOk()
            ->assertJsonPath('data.status', 'verified')->assertJsonPath('data.verified_via', 'email');
        $this->postJson('/api/v1/password/reset', ['token' => $token, 'password' => 'nouveau-mdp', 'password_confirmation' => 'nouveau-mdp'])->assertOk();
        $this->assertTrue(Hash::check('nouveau-mdp', $this->merchantUser->fresh()->password));
    }

    public function test_email_is_not_offered_without_an_address_and_nothing_is_sent_for_unknown_numbers(): void
    {
        $this->merchantUser->forceFill(['email' => null])->save();
        $this->postJson('/api/v1/password/forgot', ['phone' => $this->merchantUser->phone])->assertCreated()
            ->assertJsonPath('data.email.available', false)->assertJsonPath('data.email.hint', null);

        // Numéro sans compte : la réponse ne le révèle pas, aucun e-mail ne part
        $token = $this->postJson('/api/v1/password/forgot', ['phone' => '07 99 99 99 99'])->assertCreated()->json('token');
        $this->postJson("/api/v1/verifications/{$token}/email")->assertUnprocessable();
        Notification::assertNothingSent();

        // Inscription : l'e-mail est choisi par celui qui s'inscrit, il ne prouve pas le numéro
        $signup = $this->postJson('/api/v1/signup', [
            'business_name' => 'Boutique Awa', 'contact_name' => 'Awa Koné', 'phone' => '07 12 34 56 78', 'email' => 'boutique@example.ci',
            'pickup_zone_id' => $this->cocody->id, 'pickup_address' => 'Riviera 2',
        ])->assertCreated()->assertJsonPath('data.email.available', false);
        $this->postJson('/api/v1/verifications/'.$signup->json('token').'/email')->assertUnprocessable();
        Notification::assertNothingSent();
        // Le réglage de secours SMS ne change rien quand les SMS sont coupés
        PlatformSetting::put('sms.fallback', 'on');
        $this->getJson('/api/v1/verifications/'.$signup->json('token'))->assertJsonPath('data.sms.available', false);
    }
}
