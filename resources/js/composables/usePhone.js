import { ref } from 'vue'

/** Écran de téléphone (moins de 768 px de large) : navigation par glissement de l'application e-commerçant */
const query = window.matchMedia?.('(max-width: 767px)')
export const isPhone = ref(!!query?.matches)
query?.addEventListener?.('change', (e) => { isPhone.value = e.matches })
