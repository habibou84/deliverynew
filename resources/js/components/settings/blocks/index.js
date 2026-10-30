// Blocs de réglages de l'entreprise : un même bloc s'affiche dans la page Réglages et dans le panneau ⚙️ de la page
// concernée. Chaque bloc déclare ses champs (chargés et enregistrés ensemble) et son résumé (étiquettes en haut de page).
import { ACCEPT_MODES, AUTO_MODES, DELIVERY_MODES, EDIT_APPROVAL_MODES, VALIDATION_MODES } from '../../../views/admin/settings/options'
import AcceptanceBlock from './AcceptanceBlock.vue'
import AutoDeliveryBlock from './AutoDeliveryBlock.vue'
import AutoPickupBlock from './AutoPickupBlock.vue'
import CashAlertBlock from './CashAlertBlock.vue'
import CouriersBlock from './CouriersBlock.vue'
import DayBlock from './DayBlock.vue'
import DirectoryBlock from './DirectoryBlock.vue'
import EditApprovalBlock from './EditApprovalBlock.vue'
import FieldReminderBlock from './FieldReminderBlock.vue'
import HoldAlertBlock from './HoldAlertBlock.vue'
import MerchantSignupBlock from './MerchantSignupBlock.vue'
import UnassignedAlertsBlock from './UnassignedAlertsBlock.vue'
import ValidationBlock from './ValidationBlock.vue'
import WeeklyReportBlock from './WeeklyReportBlock.vue'

const short = (list, value) => list.find((m) => m.value === value)?.short ?? list.find((m) => m.value === value)?.label ?? '—'
const minutes = (v) => (v ? `${v} min` : 'aucune')

export const BLOCKS = {
  validation: { component: ValidationBlock, fields: ['validation_mode'], summary: (f) => [['Validation', short(VALIDATION_MODES, f.validation_mode)]] },
  editApproval: { component: EditApprovalBlock, fields: ['merchant_edit_approval'], summary: (f) => [['Modifications', short(EDIT_APPROVAL_MODES, f.merchant_edit_approval)]] },
  day: { component: DayBlock, fields: ['daily_cutoff_time', 'cutoff_by_day', 'default_max_attempts'], summary: (f) => [['Heure limite', (f.daily_cutoff_time || '').slice(0, 5) || 'aucune']] },
  couriers: {
    component: CouriersBlock,
    fields: ['require_delivery_code', 'offline_courier_actions', 'relay_required', 'pickup_mission_custody', 'depot_closes_at'],
    summary: (f) => [['Code de livraison', f.require_delivery_code ? 'exigé' : 'non'], ['Hors connexion', f.offline_courier_actions ? 'oui' : 'non'], ['Relais', f.relay_required ? 'obligatoire' : 'libre']],
  },
  autoPickup: { component: AutoPickupBlock, fields: ['auto_pickup'], summary: (f) => [['Ramassage auto', short(AUTO_MODES, f.auto_pickup)]] },
  autoDelivery: {
    component: AutoDeliveryBlock,
    fields: ['auto_delivery', 'auto_delivery_max_cod', 'same_courier_delivery'],
    summary: (f) => [['Jour même', short(DELIVERY_MODES, f.auto_delivery)]],
  },
  acceptance: {
    component: AcceptanceBlock,
    fields: ['mission_acceptance', 'unreachable_minutes', 'late_pickup_alert_minutes', 'courier_available_alert'],
    summary: (f) => [['Missions', short(ACCEPT_MODES, f.mission_acceptance)]],
  },
  unassignedAlerts: {
    component: UnassignedAlertsBlock,
    fields: ['pickup_assign_alert_minutes', 'delivery_assign_alert_minutes'],
    summary: (f) => [['Alerte ramassage', minutes(f.pickup_assign_alert_minutes)], ['Alerte livraison', minutes(f.delivery_assign_alert_minutes)]],
  },
  holdAlert: { component: HoldAlertBlock, fields: ['parcel_hold_alert_hours'], summary: (f) => [['Alerte', f.parcel_hold_alert_hours ? `après ${f.parcel_hold_alert_hours} h` : 'aucune']] },
  fieldReminder: { component: FieldReminderBlock, fields: ['field_alert_reminder_minutes'], summary: (f) => [['Relance', minutes(f.field_alert_reminder_minutes)]] },
  weeklyReport: { component: WeeklyReportBlock, fields: ['weekly_report'], summary: (f) => [['Point de la semaine', f.weekly_report ? 'envoyé le lundi' : 'non envoyé']] },
  cashAlert: {
    component: CashAlertBlock,
    fields: ['cash_alert_hours', 'cash_alert_amount', 'cash_alert_courier', 'cash_close_reminder_at', 'cash_close_summary'],
    summary: (f) => [['Argent gardé', f.cash_alert_hours ? `alerte après ${f.cash_alert_hours} h` : 'pas d\'alerte']],
  },
  merchantSignup: { component: MerchantSignupBlock, fields: ['merchant_signup'], summary: (f) => [['Inscriptions', f.merchant_signup ? 'ouvertes' : 'sur demande']] },
  directory: { component: DirectoryBlock, fields: ['directory_listed', 'directory_pitch'], summary: (f) => [['Annuaire', f.directory_listed ? 'visible' : 'absent']] },
}

/** Champs de plusieurs blocs, sans doublon */
export const fieldsOf = (keys) => [...new Set(keys.flatMap((k) => BLOCKS[k].fields))]
