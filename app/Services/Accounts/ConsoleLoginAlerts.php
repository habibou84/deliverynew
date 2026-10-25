<?php

namespace App\Services\Accounts;

use App\Models\PlatformAuditLog;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\ConsoleLoginAlert;
use App\Services\Platform\PlatformWhatsApp;
use App\Services\Platform\WavePayments;
use App\Services\Platform\WaveSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Alerte après chaque connexion à la console (e-mail, WhatsApp en secours), avec un lien signé « Ce n'était pas
 * moi » : toutes les sessions fermées, appareils retenus oubliés, nouveau mot de passe.
 */
class ConsoleLoginAlerts
{
    public function __construct(private readonly WaveSettings $mail, private readonly PlatformWhatsApp $whatsapp) {}

    public function send(User $user, string $device, string $ip): void
    {
        $url = WavePayments::consoleUrl(URL::temporarySignedRoute('console.not-me', now()->addDays(7), ['user' => $user->id], absolute: false));
        $when = now()->timezone('Africa/Abidjan')->translatedFormat('l j F Y à H:i');
        $label = self::deviceName($device);

        $mailer = $this->mail->mailer();
        if (filled($user->email) && $mailer !== null) {
            try {
                Notification::route('mail', $user->email)->notifyNow(new ConsoleLoginAlert($user->name, $when, $label, $ip, $url, $mailer['mailer'], $mailer['from']));

                return;
            } catch (Throwable $e) {
                Log::warning('Alerte de connexion console : e-mail non envoyé', ['error' => $e->getMessage()]);
            }
        }
        if (filled($user->phone) && $this->whatsapp->connected()) {
            try {
                $this->whatsapp->notify($user->phone, "Jibiat · Nouvelle connexion à la console : {$when}, {$label}, IP {$ip}.\nCe n'était pas vous ? {$url}");
            } catch (Throwable) {
                // Aucun moyen d'alerter : la connexion reste au journal
            }
        }
    }

    /**
     * « Ce n'était pas moi » : nouveau mot de passe, toutes les sessions fermées, appareils oubliés.
     */
    public function secure(User $user, string $password, ?string $ip): void
    {
        DB::transaction(function () use ($user, $password, $ip) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
            TrustedDevice::query()->where('user_id', $user->id)->delete();
            PlatformAuditLog::create([
                'user_id' => $user->id, 'action' => 'console.not-me', 'method' => 'POST', 'path' => '/console/securite/pas-moi', 'status' => 200,
                'data' => ['input' => ['password' => '••••']], 'ip' => $ip,
            ]);
        });
    }

    /** Nom lisible d'un navigateur (texte envoyé à la connexion) */
    public static function deviceName(string $agent): string
    {
        $browser = collect(['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'])
            ->first(fn ($label, $key) => str_contains($agent, $key)) ?? 'Navigateur';
        $system = collect(['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Mac OS' => 'Mac', 'Linux' => 'Linux'])
            ->first(fn ($label, $key) => str_contains($agent, $key));

        return $browser.($system ? ' · '.$system : '');
    }
}
