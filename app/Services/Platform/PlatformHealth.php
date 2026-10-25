<?php

namespace App\Services\Platform;

use App\Enums\Role;
use App\Models\PlatformWavePayment;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Santé de la plateforme pour la console : files d'attente, tâches planifiées, WhatsApp, e-mail, SMS, disque,
 * sauvegardes, réglages de sécurité du serveur. Chaque point est « ok », « warning » ou « error », avec ce qu'il
 * faut faire.
 */
class PlatformHealth
{
    /** Battement des tâches planifiées (écrit chaque minute par le planificateur) */
    public const HEARTBEAT = 'platform:scheduler-heartbeat';

    public function __construct(private readonly WaveSettings $wave, private readonly PlatformWhatsApp $whatsapp) {}

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        return array_values(array_filter([
            $this->guard('scheduler', 'Tâches planifiées', fn () => $this->scheduler()),
            $this->guard('queues', 'Files d\'attente', fn () => $this->queues()),
            $this->guard('failed_jobs', 'Tâches en échec (24 h)', fn () => $this->failedJobs()),
            $this->guard('whatsapp', 'WhatsApp de Jibiat', fn () => $this->platformWhatsApp()),
            $this->guard('companies_whatsapp', 'WhatsApp des entreprises', fn () => $this->companiesWhatsApp()),
            $this->guard('mail', 'E-mail', fn () => $this->mail()),
            $this->guard('sms', 'SMS', fn () => $this->sms()),
            $this->guard('disk', 'Espace disque', fn () => $this->disk()),
            $this->guard('backup', 'Sauvegarde de la base', fn () => $this->backup()),
            $this->guard('wave', 'Paiements Wave en attente', fn () => $this->wavePending()),
            $this->guard('security', 'Réglages de sécurité du serveur', fn () => $this->security()),
            $this->guard('two_factor', 'Code de connexion à la console', fn () => $this->twoFactor()),
        ]));
    }

    /**
     * Points en erreur ou à surveiller (pour les alertes).
     *
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function problems(): array
    {
        return array_values(array_filter($this->checks(), fn (array $c) => $c['status'] !== 'ok'));
    }

    /**
     * @param  callable(): array{0: string, 1: string}  $check
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function guard(string $key, string $label, callable $check): array
    {
        try {
            [$status, $detail] = $check();
        } catch (Throwable $e) {
            [$status, $detail] = ['error', 'Vérification impossible : '.mb_substr($e->getMessage(), 0, 160)];
        }

        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    /** @return array{0: string, 1: string} */
    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT);
        if ($beat === null) {
            return ['error', 'Aucun passage enregistré : vérifiez la ligne « schedule:run » dans la crontab du serveur.'];
        }
        $minutes = (int) now()->diffInMinutes(now()->parse($beat), true);

        return $minutes <= 3 ? ['ok', 'Dernier passage il y a '.$minutes.' min.'] : ['error', 'Arrêtées depuis '.$minutes.' min : relances, factures et alertes ne partent plus.'];
    }

    /** @return array{0: string, 1: string} */
    private function queues(): array
    {
        $sizes = [];
        foreach (['default', 'messages'] as $queue) {
            $sizes[$queue] = Queue::size($queue);
        }
        $total = array_sum($sizes);
        $detail = collect($sizes)->map(fn ($n, $q) => "{$q} : {$n}")->implode(' · ');

        return match (true) {
            $total >= 1000 => ['error', $detail.'. Les envois prennent du retard : Horizon est-il lancé (supervisorctl status) ?'],
            $total >= 200 => ['warning', $detail.'. File chargée.'],
            default => ['ok', $detail],
        };
    }

    /** @return array{0: string, 1: string} */
    private function failedJobs(): array
    {
        $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return match (true) {
            $count >= 20 => ['error', $count.' tâches en échec : voir storage/logs/laravel.log.'],
            $count > 0 => ['warning', $count.' tâche(s) en échec.'],
            default => ['ok', 'Aucune.'],
        };
    }

    /** @return array{0: string, 1: string} */
    private function platformWhatsApp(): array
    {
        $state = $this->whatsapp->state();

        return $state['status'] === 'connected'
            ? ['ok', 'Relié'.($state['phone'] ? ' ('.$state['phone'].')' : '').($state['channel'] === 'official' ? ', connexion officielle.' : ', par QR code.')]
            : ['error', 'Non relié : les alertes de paiement Wave et les codes de connexion ne partent pas. Console › WhatsApp.'];
    }

    /** @return array{0: string, 1: string} */
    private function companiesWhatsApp(): array
    {
        $accounts = WhatsAppAccount::query()->withoutGlobalScopes()->where('owner_type', 'company');
        $disconnected = (clone $accounts)->where('status', 'disconnected')->count();
        $connected = (clone $accounts)->where('status', 'connected')->count();

        return $disconnected > 0
            ? ['warning', $disconnected.' entreprise(s) déconnectée(s), '.$connected.' reliée(s) : elles doivent rescanner leur QR code ou renouveler leur jeton.']
            : ['ok', $connected.' entreprise(s) reliée(s).'];
    }

    /** @return array{0: string, 1: string} */
    private function mail(): array
    {
        $gmail = app(GmailConnection::class)->state();
        if ($gmail['error']) {
            return ['error', $gmail['error']];
        }
        if ($gmail['connected']) {
            return ['ok', 'Gmail relié ('.$gmail['email'].').'];
        }

        return $this->wave->mailReady() ? ['ok', 'Un serveur d\'e-mail est réglé.'] : ['warning', 'Aucun serveur d\'e-mail : facultatif si WhatsApp est relié.'];
    }

    /** @return array{0: string, 1: string} */
    private function sms(): array
    {
        return $this->wave->smsReady() ? ['ok', 'Fournisseur de SMS actif.'] : ['warning', 'Pas de fournisseur de SMS : les SMS de secours ne partent pas.'];
    }

    /** @return array{0: string, 1: string} */
    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (! $free || ! $total) {
            return ['warning', 'Espace disque illisible.'];
        }
        $used = (int) round(100 - $free / $total * 100);
        $detail = $used.' % utilisé, '.round($free / 1024 ** 3, 1).' Go libres.';

        return match (true) {
            $used >= 90 => ['error', $detail.' Libérez de la place (anciennes sauvegardes, journaux).'],
            $used >= 80 => ['warning', $detail],
            default => ['ok', $detail],
        };
    }

    /** @return array{0: string, 1: string} */
    private function backup(): array
    {
        $dir = (string) config('platform.backup_path');
        $files = $dir !== '' && is_dir($dir) ? (glob(rtrim($dir, '/').'/*') ?: []) : [];
        if ($files === []) {
            return ['error', 'Aucune sauvegarde trouvée dans '.($dir ?: '(dossier non réglé)').' : voir docs/MAINTENANCE.md.'];
        }
        $latest = max(array_map('filemtime', $files));
        $hours = (int) floor((time() - $latest) / 3600);

        return $hours <= 26
            ? ['ok', 'Dernière sauvegarde il y a '.$hours.' h.']
            : ['error', 'Dernière sauvegarde il y a '.$hours.' h : la sauvegarde de la nuit ne tourne plus.'];
    }

    /** @return array{0: string, 1: string} */
    private function wavePending(): array
    {
        $late = PlatformWavePayment::query()->where('status', PlatformWavePayment::PENDING)->where('created_at', '<', now()->subDay())->count();

        return $late > 0 ? ['warning', $late.' paiement(s) attendent depuis plus de 24 h : Console › Paiements Wave.'] : ['ok', 'Rien en retard.'];
    }

    /** @return array{0: string, 1: string} */
    private function twoFactor(): array
    {
        $admins = User::query()->whereNull('company_id')->where('status', 'active')->role(Role::SuperAdmin->value)->get();
        $mail = $this->wave->mailReady();
        $whatsapp = $this->whatsapp->connected();
        $without = $admins->filter(fn (User $u) => ! (($mail && filled($u->email)) || ($whatsapp && filled($u->phone))));
        if ($without->isNotEmpty()) {
            return ['error', 'Le code ne peut pas partir pour '.$without->pluck('name')->implode(', ').' : la console s\'ouvre avec le seul mot de passe. Reliez Gmail et renseignez l\'e-mail du compte.'];
        }

        return ['ok', 'Code envoyé '.($mail ? 'par e-mail' : 'par WhatsApp').' à chaque connexion (appareil retenu 30 jours).'];
    }

    /** @return array{0: string, 1: string} */
    private function security(): array
    {
        $issues = [];
        if (app()->isProduction() && config('app.debug')) {
            $issues[] = 'APP_DEBUG est activé en production (les erreurs affichent des secrets) : mettre APP_DEBUG=false';
        }
        if (app()->isProduction() && ! str_starts_with((string) config('app.url'), 'https://')) {
            $issues[] = 'APP_URL n\'est pas en https';
        }
        if (strlen((string) config('messaging.whatsapp.bridge.token')) < 32) {
            $issues[] = 'WHATSAPP_BRIDGE_TOKEN absent ou trop court (32 caractères au moins)';
        }

        return $issues === [] ? ['ok', 'Mode débogage coupé, https, jeton du service WhatsApp solide.'] : ['error', implode(' ; ', $issues).'.'];
    }
}
