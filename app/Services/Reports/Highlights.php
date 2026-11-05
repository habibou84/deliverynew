<?php

namespace App\Services\Reports;

/**
 * « À retenir » en haut de chaque onglet des statistiques : trois ou quatre phrases courtes tirées des chiffres,
 * avec un lien pour agir. Les mots entre ** sont mis en gras à l'écran.
 *
 * @phpstan-type Line array{text: string, to?: string, level?: string}
 */
class Highlights
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, string>>
     */
    public function for(string $tab, array $data): array
    {
        $lines = match ($tab) {
            'overview' => $this->overview($data),
            'deliveries' => $this->deliveries($data),
            'failures' => $this->failures($data),
            'couriers' => $this->couriers($data),
            'merchants' => $this->merchants($data),
            'zones' => $this->zones($data),
            'finances' => $this->finances($data),
            'live' => $this->live($data),
            default => [],
        };

        return array_slice(array_values(array_filter($lines)), 0, 4);
    }

    private function overview(array $d): array
    {
        $k = $d['kpis'];
        $p = $d['previous_kpis'];
        $lines = [];
        if ($k['created'] === 0) {
            return [['text' => 'Aucune course sur la période.']];
        }
        $change = $this->change($k['created'], $p['created']);
        $lines[] = ['text' => $change === null
            ? "**{$this->plural($k['created'], 'course')}** créée".($k['created'] > 1 ? 's' : '').' sur la période.'
            : "**{$this->signed($change)} % de courses** par rapport à la période précédente ({$this->n($k['created'])}).", 'level' => $change !== null && $change < 0 ? 'bad' : 'good'];
        if ($k['success_rate'] !== null && $p['success_rate'] !== null) {
            $diff = round($k['success_rate'] - $p['success_rate'], 1);
            $lines[] = ['text' => "Taux de livraison : **{$this->pct($k['success_rate'])}** (".($diff >= 0 ? '+' : '−').$this->pct(abs($diff), ' pt').').', 'level' => $diff < -1 ? 'bad' : 'good'];
        }
        if (($k['failed_attempts'] ?? 0) > 0) {
            $lines[] = ['text' => "**{$this->plural($k['failed_attempts'], 'échec')}** de livraison : voir pourquoi et où.", 'to' => '/admin/statistiques?onglet=failures'];
        }
        if (($d['with_couriers'] ?? 0) > 0) {
            $lines[] = ['text' => "**{$this->money($d['with_couriers'])}** encore chez les livreurs.", 'to' => '/admin/caisse'];
        }

        return $lines;
    }

    private function deliveries(array $d): array
    {
        $lines = [];
        if ($d['first_attempt_rate'] !== null) {
            $lines[] = ['text' => "**{$this->pct($d['first_attempt_rate'])}** des colis sont livrés du premier coup."];
        }
        if ($d['delays']['total'] !== null) {
            $lines[] = ['text' => 'Délai habituel entre la création et la livraison : **'.$this->duration($d['delays']['total']).'**.'];
        }
        $top = $d['failure_reasons'][0] ?? null;
        if ($top) {
            $lines[] = ['text' => "Premier motif d'échec : **{$top['label']}** ({$top['count']}).", 'to' => '/admin/statistiques?onglet=failures'];
        }

        return $lines;
    }

    private function failures(array $d): array
    {
        $k = $d['kpis'];
        if ($k['failed_attempts'] === 0) {
            return [['text' => 'Aucun échec de livraison sur la période. Bravo !', 'level' => 'good']];
        }
        $lines = [['text' => "**{$this->plural($k['failed_attempts'], 'échec')}** de livraison ({$this->pct($k['failure_rate'])} des passages).", 'level' => 'bad']];
        $top = collect($d['reasons'])->first();
        $total = collect($d['reasons'])->sum('count');
        if ($top && $total) {
            $lines[] = ['text' => "Premier motif : **{$top['label']}** (".round($top['count'] / $total * 100).' % des échecs).'];
        }
        if ($zone = collect($d['by_zone'])->first()) {
            $lines[] = ['text' => "Zone la plus difficile : **{$zone['name']}** ({$this->pct($zone['failure_rate'])} d'échecs)."];
        }
        if ($merchant = collect($d['by_merchant'])->first()) {
            $lines[] = ['text' => "**{$merchant['name']}** a {$this->pct($merchant['failure_rate'])} d'échecs : proposer l'appel avant départ ou un acompte."];
        }
        if ($k['lost_fees']) {
            $lines[] = ['text' => "Les colis revenus ont coûté **{$this->money($k['lost_fees'])}** de frais non gagnés."];
        }

        return $lines;
    }

    private function couriers(array $d): array
    {
        $rows = collect($d['couriers'])->filter(fn ($r) => $r['delivered'] + $r['failed'] >= 10);
        $lines = [];
        if ($best = $rows->sortByDesc(fn ($r) => [$r['success_rate'], $r['delivered']])->first()) {
            $lines[] = ['text' => "**{$best['name']}** est en tête : {$this->n($best['delivered'])} livrées, {$this->pct($best['success_rate'])} de réussite.", 'level' => 'good'];
        }
        $weak = $rows->filter(fn ($r) => $r['success_rate'] !== null && $r['success_rate'] < 80)->pluck('name');
        if ($weak->isNotEmpty()) {
            $lines[] = ['text' => '**'.$weak->take(3)->join(', ', ' et ').'** '.($weak->count() > 1 ? 'sont' : 'est').' sous 80 % de réussite : à accompagner.', 'level' => 'bad'];
        }
        if ($peak = $this->peak($d['workload'] ?? [])) {
            $lines[] = ['text' => "Moment le plus chargé : **{$peak}**."];
        }

        return $lines;
    }

    private function merchants(array $d): array
    {
        $rows = collect($d['merchants']);
        $lines = [];
        $losing = $rows->filter(fn ($r) => ($r['margin'] ?? 0) < 0)->sortBy('margin');
        if ($worst = $losing->first()) {
            $lines[] = ['text' => "**{$worst['name']}** fait perdre de l'argent à l'agence ({$this->money($worst['margin'])}) : revoir ses conditions.", 'level' => 'bad'];
        }
        $down = $rows->where('trend', 'down')->sortBy('evolution');
        if ($down->isNotEmpty()) {
            $first = $down->first();
            $lines[] = ['text' => "**{$down->count()} e-commerçant".($down->count() > 1 ? 's baissent' : ' baisse')."**, dont {$first['name']} ({$first['evolution']} %) : à appeler.", 'level' => 'bad'];
        }
        $up = $rows->where('trend', 'up')->sortByDesc('evolution');
        if ($up->isNotEmpty()) {
            $first = $up->first();
            $lines[] = ['text' => "**{$first['name']}** monte (+{$first['evolution']} %) : proposer le programme Premium.", 'to' => '/admin/premium', 'level' => 'good'];
        }
        if (($d['totals']['inactive'] ?? 0) > 0) {
            $lines[] = ['text' => "**{$d['totals']['inactive']}** sans course depuis {$d['inactive_days']} jours : à relancer."];
        }

        return $lines;
    }

    private function zones(array $d): array
    {
        $rows = collect($d['zones'])->filter(fn ($r) => $r['delivered'] + $r['failed'] >= 5);
        $lines = [];
        if ($worst = $rows->filter(fn ($r) => $r['success_rate'] !== null)->sortBy('success_rate')->first()) {
            $lines[] = ['text' => "Zone la plus difficile : **{$worst['name']}** ({$this->pct($worst['success_rate'])} de réussite).", 'level' => 'bad'];
        }
        $losing = $rows->filter(fn ($r) => $r['margin_per_order'] !== null && $r['margin_per_order'] < 0)->sortBy('margin_per_order');
        if ($zone = $losing->first()) {
            $lines[] = ['text' => "**{$zone['name']}** fait perdre {$this->money(-$zone['margin_per_order'])} par course : revoir le prix.", 'to' => '/admin/tarifs', 'level' => 'bad'];
        }
        if ($best = $rows->filter(fn ($r) => $r['margin_per_order'] !== null)->sortByDesc('margin_per_order')->first()) {
            $lines[] = ['text' => "**{$best['name']}** rapporte le plus par course ({$this->money($best['margin_per_order'])}).", 'level' => 'good'];
        }

        return $lines;
    }

    private function finances(array $d): array
    {
        $lines = [];
        $change = $this->change($d['margin'], $d['previous']['margin'] ?? null);
        $lines[] = ['text' => "Marge de la période : **{$this->money($d['margin'])}**".($change === null ? '.' : " ({$this->signed($change)} %)."), 'level' => $d['margin'] < 0 || ($change !== null && $change < 0) ? 'bad' : 'good'];
        if ($d['margin_per_order'] !== null) {
            $lines[] = ['text' => "L'agence garde **{$this->money($d['margin_per_order'])}** par course livrée."];
        }
        if ($d['circulation']['with_couriers'] > 0) {
            $lines[] = ['text' => "**{$this->money($d['circulation']['with_couriers'])}** encore chez les livreurs.", 'to' => '/admin/caisse'];
        }
        if ($d['circulation']['to_merchants'] > 0) {
            $lines[] = ['text' => "**{$this->money($d['circulation']['to_merchants'])}** à reverser aux e-commerçants."];
        }

        return $lines;
    }

    private function live(array $d): array
    {
        $k = $d['kpis'];
        if ($k['lives'] === 0) {
            return [['text' => 'Aucun live sur la période. Proposez l\'outil live à vos e-commerçants.']];
        }
        $lines = [['text' => "**{$k['lives']} live".($k['lives'] > 1 ? 's' : '')."** par {$k['merchants']} e-commerçant".($k['merchants'] > 1 ? 's' : '').", **{$this->plural($k['orders'], 'commande')}** prise".($k['orders'] > 1 ? 's' : '').'.', 'level' => 'good']];
        if ($k['share'] !== null) {
            $lines[] = ['text' => "Le live amène **{$this->pct($k['share'])} des courses**."];
        }
        if ($k['delivered_rate'] !== null) {
            $lines[] = ['text' => "Les colis du live sont livrés à **{$this->pct($k['delivered_rate'])}**.", 'level' => $k['delivered_rate'] < 75 ? 'bad' : 'good'];
        }

        return $lines;
    }

    /** Créneau le plus chargé d'une grille jour × heure */
    private function peak(array $grid): ?string
    {
        $best = null;
        foreach ($grid as $day => $hours) {
            foreach ($hours as $hour => $count) {
                if ($count > 0 && ($best === null || $count > $best[2])) {
                    $best = [$day, $hour, $count];
                }
            }
        }
        $days = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

        return $best ? "le {$days[$best[0]]} vers {$best[1]} h" : null;
    }

    private function change(?int $now, ?int $before): ?int
    {
        return $now === null || ! $before ? null : (int) round(($now - $before) / abs($before) * 100);
    }

    private function signed(int $v): string
    {
        return $v > 0 ? "+{$v}" : ($v < 0 ? '−'.abs($v) : '0');
    }

    private function plural(int $v, string $word): string
    {
        return $this->n($v).' '.$word.($v > 1 ? 's' : '');
    }

    private function n(int $v): string
    {
        return number_format($v, 0, ',', ' ');
    }

    private function pct(?float $v, string $unit = ' %'): string
    {
        return $v === null ? '—' : str_replace('.', ',', (string) $v).$unit;
    }

    private function money(int $v): string
    {
        return ($v < 0 ? '−' : '').number_format(abs($v), 0, ',', ' ').' F';
    }

    private function duration(int $minutes): string
    {
        return $minutes < 60 ? "{$minutes} min" : intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
