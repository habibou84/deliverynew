// Bouton « Connecter WhatsApp » : inscription intégrée de Meta (Embedded Signup).
// La fenêtre de Meta laisse l'entreprise se connecter à Facebook, choisir ou créer son compte
// WhatsApp Business et confirmer son numéro ; elle renvoie un code d'autorisation (à échanger
// côté serveur) et les identifiants du numéro et du compte.

let sdk = null

function loadSdk(appId, version) {
  if (sdk) return sdk
  sdk = new Promise((resolve, reject) => {
    window.fbAsyncInit = () => {
      window.FB.init({ appId, autoLogAppEvents: true, xfbml: false, version })
      resolve(window.FB)
    }
    const script = document.createElement('script')
    script.src = 'https://connect.facebook.net/fr_FR/sdk.js'
    script.async = true
    script.defer = true
    script.crossOrigin = 'anonymous'
    script.onerror = () => {
      sdk = null
      reject(new Error('Impossible de charger Facebook. Vérifiez votre connexion ou désactivez le bloqueur de publicités.'))
    }
    document.body.appendChild(script)
  })
  return sdk
}

function fromFacebook(origin) {
  try {
    return /(^|\.)facebook\.com$/.test(new URL(origin).hostname)
  } catch {
    return false
  }
}

/**
 * Ouvre la fenêtre de Meta. Résout { code, phone_number_id, waba_id } ;
 * rejette avec un message lisible (fenêtre fermée, erreur, aucun numéro choisi).
 * coexistence : relier le numéro de l'application WhatsApp Business, qui reste utilisée sur le téléphone
 * (e-commerçants, commandes du live).
 */
export async function connectWhatsApp({ app_id: appId, config_id: configId, graph_version: version }, { coexistence = false } = {}) {
  const FB = await loadSdk(appId, version)

  return new Promise((resolve, reject) => {
    let session = null
    let code = null
    let settled = false

    const settle = (fn, value) => {
      if (settled) return
      settled = true
      window.removeEventListener('message', onMessage)
      fn(value)
    }
    const finish = () => {
      if (code && session) settle(resolve, { code, ...session })
    }

    // Numéro et compte choisis dans la fenêtre de Meta
    function onMessage(event) {
      if (!fromFacebook(event.origin)) return
      let data
      try {
        data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data
      } catch {
        return
      }
      if (data?.type !== 'WA_EMBEDDED_SIGNUP') return

      if (data.event === 'FINISH' || (data.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING' && data.data?.phone_number_id)) {
        session = { phone_number_id: String(data.data.phone_number_id), waba_id: String(data.data.waba_id) }
        finish()
      } else if (data.event === 'CANCEL') {
        // La raison donnée par Meta, ou l'étape où la personne s'est arrêtée
        const step = data.data?.current_step ? ` à l'étape « ${data.data.current_step} »` : ''
        settle(reject, new Error(data.data?.error_message || `Connexion interrompue${step}. Vous pouvez recommencer quand vous voulez.`))
      } else if (data.event === 'FINISH_ONLY_WABA') {
        settle(reject, new Error('Aucun numéro n\'a été choisi : recommencez et ajoutez le numéro WhatsApp de l\'entreprise.'))
      }
    }
    window.addEventListener('message', onMessage)

    FB.login((response) => {
      code = response?.authResponse?.code || null
      if (!code) {
        settle(reject, new Error('Connexion annulée.'))
        return
      }
      finish()
      // Le numéro arrive normalement avant ; sinon la fenêtre a été fermée trop tôt
      setTimeout(() => settle(reject, new Error('Meta n\'a pas indiqué le numéro choisi : recommencez la connexion.')), 8000)
    }, {
      config_id: configId,
      response_type: 'code',
      override_default_response_type: true,
      extras: { setup: {}, featureType: coexistence ? 'whatsapp_business_app_onboarding' : '', sessionInfoVersion: '3' },
    })
  })
}

/** Téléphone : la petite fenêtre de Facebook s'y ferme souvent avant de démarrer, la page entière marche */
export function prefersFullPage() {
  return window.matchMedia?.('(pointer: coarse)').matches || /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)
}

/** Navigateur intégré de WhatsApp, Facebook, Instagram, TikTok… : la connexion à Facebook y échoue souvent */
export function inAppBrowser() {
  return /FBAN|FBAV|FB_IAB|Instagram|WhatsApp|musical_ly|TikTok|Line\/|; wv\)/i.test(navigator.userAgent)
}

/**
 * Connexion par la page entière : adresse de la fenêtre Facebook ; Facebook revient sur redirectUri avec
 * ?code=…&state=… (state : le ticket de la connexion).
 */
export function facebookDialogUrl({ app_id: appId, config_id: configId, graph_version: version }, redirectUri, state, { coexistence = false } = {}) {
  const extras = { setup: {}, sessionInfoVersion: '3', ...(coexistence ? { featureType: 'whatsapp_business_app_onboarding' } : {}) }
  const query = new URLSearchParams({
    client_id: appId, config_id: configId, response_type: 'code', override_default_response_type: 'true',
    redirect_uri: redirectUri, state, extras: JSON.stringify(extras),
  })
  return `https://www.facebook.com/${version || 'v21.0'}/dialog/oauth?${query}`
}
