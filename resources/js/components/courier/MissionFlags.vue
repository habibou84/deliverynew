<script>
import { defineComponent, h } from 'vue'
import Icone from '../ui/Icone.vue'
import { money } from '../../utils/format'

// Petites étiquettes d'une ou plusieurs missions : express, relais, modifiée, consigne, hors ligne, à expédier, gain
export default defineComponent({
  props: { missions: { type: Array, required: true } },
  setup(props) {
    const flag = (cls, icon, text, title) => h('span', { class: `inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${cls}`, title }, [h(Icone, { nom: icon, taille: 'petite' }), text])
    return () => {
      const ms = props.missions
      const gain = ms.reduce((sum, m) => sum + (m.gain || 0), 0)
      const unread = ms.reduce((sum, m) => sum + (m.unread_messages || 0), 0)
      return [
        ms.some((m) => m.order?.package?.is_express) && flag('bg-red-100 text-red-700', 'rapide', 'Express'),
        ms.some((m) => m.relay_from) && flag('bg-violet-100 text-violet-800', 'relais', 'Relais'),
        ms.some((m) => m.last_edit && !m.last_edit.seen) && flag('bg-amber-100 text-amber-800', 'modifier', 'Modifiée'),
        unread && flag('bg-sky-100 text-sky-800', 'message', String(unread), 'Consignes de l\'agence'),
        ms.some((m) => m.offline) && flag('bg-sky-100 text-sky-800', 'hors-ligne', 'À envoyer'),
        ms.some((m) => m.type === 'delivery' && m.order?.is_shipping) && flag('bg-indigo-100 text-indigo-800', 'expedition', 'À expédier'),
        gain && flag('bg-emerald-100 text-emerald-800', 'gain', `+ ${money(gain)}`, 'Votre gain prévu'),
      ].filter(Boolean)
    }
  },
})
</script>
