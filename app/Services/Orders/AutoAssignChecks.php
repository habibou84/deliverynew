<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\CourierRule;

/**
 * Réglages qui manquent pour que les courses soient assignées automatiquement aux livreurs, du plus bloquant au moins
 * bloquant. Affichés en bandeau sur la page Courses, chacun avec son action (réglage à ouvrir ou page).
 */
class AutoAssignChecks
{
    /** @return list<array{key: string, text: string, why: string, action: string, block: ?string, to: ?string}> */
    public function missing(Company $company): array
    {
        $items = [];
        $rules = CourierRule::withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true);
        $pickup = $company->auto_pickup ?? 'off';
        $delivery = $company->auto_delivery ?? 'off';

        if ($company->automationPaused()) {
            $items[] = $this->item('paused', 'Assignation automatique suspendue',
                'Depuis le '.$company->automation_paused_at->format('d/m/Y à H:i').', plus aucune course n\'est assignée automatiquement.', 'Reprendre', null, '/admin/planning');
        }
        if (! (clone $rules)->exists()) {
            $items[] = $this->item('planning', 'Aucune règle au planning',
                'Le planning dit quel livreur ramasse et livre où : sans lui, aucune course ne peut être assignée automatiquement.', 'Remplir le planning', null, '/admin/planning');
        }
        if ($pickup === 'off') {
            $items[] = $this->item('auto_pickup', 'Assignation automatique des ramassages désactivée',
                'Chaque ramassage attend qu\'un dispatcher choisisse le livreur.', 'Activer', 'autoPickup', null);
        } elseif ($pickup === 'trial') {
            $items[] = $this->item('auto_pickup', 'Assignation automatique des ramassages à l\'essai',
                'Le livreur choisi est seulement noté sur la course : rien n\'est encore assigné.', 'Passer en actif', 'autoPickup', null);
        }
        if ($delivery === 'off') {
            $items[] = $this->item('auto_delivery', 'Assignation automatique des livraisons du jour désactivée',
                'Une fois le colis récupéré, la livraison attend qu\'un dispatcher choisisse le livreur.', 'Activer', 'autoDelivery', null);
        } elseif ($delivery === 'trial') {
            $items[] = $this->item('auto_delivery', 'Assignation automatique des livraisons à l\'essai',
                'Le livreur choisi est seulement noté sur la course : rien n\'est encore assigné.', 'Passer en actif', 'autoDelivery', null);
        }
        if (($company->validation_mode ?? 'manual') === 'manual') {
            $items[] = $this->item('validation', 'Validation des courses manuelle',
                'Une course n\'est assignée qu\'après sa validation par un dispatcher.', 'Valider automatiquement', 'validation', null);
        }
        if (empty($company->daily_cutoff_time)) {
            $items[] = $this->item('cutoff', 'Heure limite du jour non fixée',
                'Sans elle, une course arrivée tard est assignée pour aujourd\'hui au lieu de demain.', 'Fixer l\'heure limite', 'day', null);
        }

        return $items;
    }

    private function item(string $key, string $text, string $why, string $action, ?string $block, ?string $to): array
    {
        return compact('key', 'text', 'why', 'action', 'block', 'to');
    }
}
