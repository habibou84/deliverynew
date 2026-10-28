<?php

namespace App\Services\Platform;

use App\Models\AppOption;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Applications à leur nom dans les formules : pour Android et pour iPhone, incluses, en option (prix par mois) ou
 * non disponibles. L'entreprise demande l'option, Jibiat l'accepte : elle est alors facturée au prorata des jours
 * de chaque période, au prix retenu à l'acceptation. Une application déjà installée continue de marcher sans
 * l'option ; seules les nouvelles versions sont bloquées.
 */
class AppOptions
{
    public const PLATFORMS = ['android', 'ios'];

    public const INCLUDED = 'included';

    public const OPTION = 'option';

    public const NONE = 'none';

    /** Plateformes pas encore fabriquées (affichées « bientôt », pas de demande possible) */
    public const SOON = ['ios'];

    public const LABELS = ['android' => 'Android', 'ios' => 'iPhone'];

    /** Mode d'une plateforme dans la formule */
    public static function mode(?Plan $plan, string $platform): string
    {
        $mode = $plan?->apps[$platform] ?? self::NONE;

        return in_array($mode, [self::INCLUDED, self::OPTION], true) ? $mode : self::NONE;
    }

    /** Prix par mois de l'option dans la formule */
    public static function price(?Plan $plan, string $platform): ?int
    {
        $price = $plan?->apps["{$platform}_price"] ?? null;

        return self::mode($plan, $platform) === self::OPTION && $price !== null ? (int) $price : null;
    }

    /**
     * Réglage des applications d'une formule, nettoyé (valeurs inconnues → non disponibles, prix seulement pour une
     * option).
     *
     * @param  array<string, mixed>  $apps
     * @return array<string, mixed>
     */
    public static function normalize(array $apps): array
    {
        $clean = [];
        foreach (self::PLATFORMS as $platform) {
            $mode = in_array($apps[$platform] ?? null, [self::INCLUDED, self::OPTION], true) ? $apps[$platform] : self::NONE;
            $clean[$platform] = $mode;
            $clean["{$platform}_price"] = $mode === self::OPTION && isset($apps["{$platform}_price"]) ? (int) $apps["{$platform}_price"] : null;
        }

        return $clean;
    }

    /**
     * Lignes du tableau des tarifs : « Applications Android à votre nom incluses », « … en option (10 000 F / mois) ».
     *
     * @return list<string>
     */
    public static function summaryLines(Plan $plan): array
    {
        $lines = [];
        foreach (self::PLATFORMS as $platform) {
            $mode = self::mode($plan, $platform);
            if ($mode === self::NONE) {
                continue;
            }
            $soon = in_array($platform, self::SOON, true) ? ' (bientôt)' : '';
            $lines[] = 'Applications '.self::LABELS[$platform].' à votre nom'.$soon.($mode === self::INCLUDED
                ? ' incluses'
                : ' en option ('.number_format((int) self::price($plan, $platform), 0, ',', ' ').' F / mois)');
        }

        return $lines;
    }

    /** Dernière demande d'option encore en cours (en attente ou active) */
    public function current(Company $company, string $platform): ?AppOption
    {
        return AppOption::query()->where('company_id', $company->id)->where('platform', $platform)
            ->whereIn('status', [AppOption::PENDING, AppOption::ACTIVE])->latest('id')->first();
    }

    /** L'entreprise a droit aux applications à son nom sur cette plateforme (incluses, ou option active) */
    public function allows(Company $company, string $platform = 'android'): bool
    {
        $mode = self::mode($company->loadMissing('plan')->plan, $platform);

        return $mode === self::INCLUDED
            || ($mode === self::OPTION && $this->current($company, $platform)?->status === AppOption::ACTIVE);
    }

    /**
     * État pour « Mon abonnement » et la console : par plateforme, ce que dit la formule et où en est l'option.
     * status : included, active, pending, available (option possible), unavailable.
     *
     * @return list<array<string, mixed>>
     */
    public function state(Company $company): array
    {
        $plan = $company->loadMissing('plan')->plan;

        return array_map(function (string $platform) use ($company, $plan) {
            $mode = self::mode($plan, $platform);
            $option = $this->current($company, $platform);
            $status = match (true) {
                $mode === self::INCLUDED => 'included',
                $mode === self::OPTION && $option?->status === AppOption::ACTIVE => 'active',
                $mode === self::OPTION && $option?->status === AppOption::PENDING => 'pending',
                $mode === self::OPTION => 'available',
                default => 'unavailable',
            };

            return [
                'platform' => $platform,
                'label' => self::LABELS[$platform],
                'mode' => $mode,
                'status' => $status,
                'price' => $option?->status === AppOption::ACTIVE ? $option->price : self::price($plan, $platform),
                'option_id' => $option?->id,
                'since' => $option?->started_at?->toDateString(),
                'soon' => in_array($platform, self::SOON, true),
                // Formules où la plateforme est incluse, ou proposée en option (pour donner envie de changer)
                'included_in' => $this->plansWith($platform, self::INCLUDED),
                'option_in' => $this->plansWith($platform, self::OPTION),
            ];
        }, self::PLATFORMS);
    }

    /** @return list<array{name: string, price: ?int}> */
    private function plansWith(string $platform, string $mode): array
    {
        return Plan::query()->where('is_active', true)->orderBy('position')->get()
            ->filter(fn (Plan $p) => self::mode($p, $platform) === $mode)
            ->map(fn (Plan $p) => ['name' => $p->name, 'price' => self::price($p, $platform)])->values()->all();
    }

    /**
     * Demande de l'option par l'entreprise (à valider par Jibiat).
     *
     * @throws ValidationException
     */
    public function request(Company $company, User $by, string $platform): AppOption
    {
        $plan = $company->loadMissing('plan')->plan;
        $fail = fn (string $message) => throw ValidationException::withMessages(['platform' => $message]);
        if (in_array($platform, self::SOON, true)) {
            $fail('Les applications iPhone arrivent bientôt : la demande sera possible à ce moment-là.');
        }
        if (self::mode($plan, $platform) === self::INCLUDED) {
            $fail('Les applications '.self::LABELS[$platform].' sont déjà incluses dans votre formule.');
        }
        if (self::mode($plan, $platform) !== self::OPTION) {
            $fail('Votre formule ne propose pas cette option : passez à une formule qui la propose.');
        }
        if ($this->current($company, $platform) !== null) {
            $fail('Une demande est déjà en cours ou l\'option est déjà active.');
        }

        return AppOption::create([
            'company_id' => $company->id,
            'platform' => $platform,
            'status' => AppOption::PENDING,
            'price' => self::price($plan, $platform),
            'requested_by' => $by->id,
        ]);
    }

    /** Acceptée par Jibiat : facturée dès aujourd'hui, au prix de la formule */
    public function accept(AppOption $option, User $by): AppOption
    {
        abort_unless($option->status === AppOption::PENDING, 422, 'Cette demande a déjà été traitée.');
        $plan = $option->company->loadMissing('plan')->plan;
        abort_unless(self::mode($plan, $option->platform) === self::OPTION, 422, 'La formule de l\'entreprise ne propose plus cette option.');
        $option->forceFill([
            'status' => AppOption::ACTIVE,
            'price' => self::price($plan, $option->platform) ?? $option->price,
            'decided_by' => $by->id,
            'started_at' => now(),
        ])->save();

        return $option;
    }

    public function refuse(AppOption $option, User $by, ?string $note = null): AppOption
    {
        abort_unless($option->status === AppOption::PENDING, 422, 'Cette demande a déjà été traitée.');
        $option->forceFill(['status' => AppOption::REFUSED, 'decided_by' => $by->id, 'note' => $note])->save();

        return $option;
    }

    /** Option arrêtée (par l'entreprise ou par Jibiat) : plus facturée à partir de demain, plus de nouvelle version */
    public function stop(AppOption $option, User $by): AppOption
    {
        abort_unless(in_array($option->status, [AppOption::PENDING, AppOption::ACTIVE], true), 422, 'Cette option est déjà arrêtée.');
        $option->forceFill([
            'status' => $option->status === AppOption::PENDING ? AppOption::REFUSED : AppOption::STOPPED,
            'decided_by' => $option->decided_by ?? $by->id,
            'stopped_at' => now(),
            'note' => $option->status === AppOption::PENDING ? 'Demande retirée' : $option->note,
        ])->save();

        return $option;
    }

    /**
     * Options à facturer du premier au dernier jour (compris) : chaque option active sur une partie de la période,
     * au prorata de ses jours (jours du mois, ou semaine sur la base de 12 mois = 52 semaines). Rien si la formule
     * inclut la plateforme ou ne la propose plus.
     *
     * @return array{amount: int, lines: list<array{platform: string, label: string, days: int, price: int, amount: int}>}
     */
    public function billable(Company $company, Plan $plan, CarbonImmutable $first, CarbonImmutable $last, string $cycle = PlatformInvoice::MONTHLY): array
    {
        $tz = $company->timezone;
        $lines = [];
        $options = AppOption::query()->where('company_id', $company->id)
            ->whereIn('status', [AppOption::ACTIVE, AppOption::STOPPED])->whereNotNull('started_at')
            ->where('started_at', '<', $last->addDay()->startOfDay()->utc())
            ->where(fn ($q) => $q->whereNull('stopped_at')->orWhere('stopped_at', '>=', $first->startOfDay()->utc()))
            ->get();
        foreach ($options as $option) {
            if (self::mode($plan, $option->platform) !== self::OPTION || ! $option->price) {
                continue;
            }
            $from = max($first->startOfDay(), $option->started_at->setTimezone($tz)->startOfDay());
            // Jour de l'arrêt : dernier jour facturé
            $to = $option->stopped_at ? min($last->startOfDay(), $option->stopped_at->setTimezone($tz)->startOfDay()) : $last->startOfDay();
            $days = $to < $from ? 0 : (int) $from->diffInDays($to) + 1;
            if ($days <= 0) {
                continue;
            }
            $perDay = $cycle === PlatformInvoice::WEEKLY ? $option->price * 12 / 52 / 7 : $option->price / $first->daysInMonth;
            $lines[] = [
                'platform' => $option->platform,
                'label' => 'Applications '.self::LABELS[$option->platform].' à votre nom',
                'days' => $days,
                'price' => $option->price,
                'amount' => (int) round($days * $perDay),
            ];
        }

        return ['amount' => array_sum(array_column($lines, 'amount')), 'lines' => $lines];
    }
}
