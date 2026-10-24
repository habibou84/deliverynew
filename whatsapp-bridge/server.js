'use strict'

/*
 * Connexion WhatsApp par QR code (comme WhatsApp Web), en attendant la validation de Meta.
 *
 * Une session par entreprise (« company-12 »), gardée dans storage/app/whatsapp-bridge/<session>. Jibiat pilote ce
 * service sur le serveur lui-même (127.0.0.1, jeton WHATSAPP_BRIDGE_TOKEN) : démarrer une session et lire son QR code,
 * envoyer un message, se déconnecter. Le service prévient Jibiat (webhook signé avec le même jeton) : état de la
 * session, messages reçus, messages envoyés, remis ou lus.
 *
 * Envois : une file par session, avec une pause entre deux messages (WHATSAPP_BRIDGE_MIN_DELAY_MS, plus un délai
 * au hasard) pour garder un rythme humain. Un numéro sans WhatsApp est refusé (Jibiat passe alors au SMS de repli).
 *
 * Réglages lus dans le .env de Jibiat (dossier parent) : WHATSAPP_BRIDGE_PORT (3100), WHATSAPP_BRIDGE_TOKEN,
 * WHATSAPP_BRIDGE_WEBHOOK (adresse de Jibiat qui reçoit les événements, par défaut APP_URL/api/webhooks/whatsapp-bridge).
 */

const crypto = require('node:crypto')
const fs = require('node:fs')
const http = require('node:http')
const path = require('node:path')
const pino = require('pino')
const QRCode = require('qrcode')
const {
  default: makeWASocket,
  Browsers,
  DisconnectReason,
  fetchLatestBaileysVersion,
  useMultiFileAuthState,
} = require('@whiskeysockets/baileys')

const ROOT = path.resolve(__dirname, '..')
const env = { ...readEnv(path.join(ROOT, '.env')), ...process.env }
const PORT = Number(env.WHATSAPP_BRIDGE_PORT || 3100)
const TOKEN = env.WHATSAPP_BRIDGE_TOKEN || ''
const WEBHOOK = env.WHATSAPP_BRIDGE_WEBHOOK || `${(env.APP_URL || 'http://localhost').replace(/\/$/, '')}/api/webhooks/whatsapp-bridge`
const DATA_DIR = env.WHATSAPP_BRIDGE_DATA || path.join(ROOT, 'storage', 'app', 'whatsapp-bridge')
const MIN_DELAY = Number(env.WHATSAPP_BRIDGE_MIN_DELAY_MS || 4000)
const EXTRA_DELAY = Number(env.WHATSAPP_BRIDGE_EXTRA_DELAY_MS || 3000)

const log = pino({ level: env.WHATSAPP_BRIDGE_LOG || 'info' })
const quiet = pino({ level: 'silent' })

if (!TOKEN) {
  log.fatal('WHATSAPP_BRIDGE_TOKEN manquant dans le .env : le service ne démarre pas.')
  process.exit(1)
}
fs.mkdirSync(DATA_DIR, { recursive: true })

/** @type {Map<string, Session>} */
const sessions = new Map()

// ---------------------------------------------------------------------------------------------------------------
// Sessions

class Session {
  constructor (id) {
    this.id = id
    this.dir = path.join(DATA_DIR, id)
    this.status = 'starting' // starting | qr | connected | disconnected | logged_out
    this.qr = null
    this.phone = null
    this.name = null
    this.sock = null
    this.queue = Promise.resolve()
    this.lastSend = 0
    // Identifiant WhatsApp d'un message envoyé => identifiant donné à Jibiat (accusés de réception)
    this.sent = new Map()
    this.stopped = false
    this.retries = 0
  }

  async start () {
    this.stopped = false
    const { state, saveCreds } = await useMultiFileAuthState(this.dir)
    let version
    try {
      version = (await fetchLatestBaileysVersion()).version
    } catch {
      version = undefined
    }
    const sock = makeWASocket({
      version,
      auth: state,
      logger: quiet,
      browser: Browsers.ubuntu('Jibiat'),
      markOnlineOnConnect: false,
      syncFullHistory: false,
    })
    this.sock = sock
    sock.ev.on('creds.update', saveCreds)
    sock.ev.on('connection.update', (update) => this.onConnection(update))
    sock.ev.on('messages.upsert', ({ messages, type }) => {
      if (type === 'notify') messages.forEach((m) => this.onMessage(m))
    })
    sock.ev.on('messages.update', (updates) => updates.forEach((u) => this.onReceipt(u)))
  }

  async onConnection ({ connection, lastDisconnect, qr }) {
    if (qr) {
      this.qr = await QRCode.toDataURL(qr, { margin: 1, width: 300 })
      this.setStatus('qr')
    }
    if (connection === 'open') {
      this.qr = null
      this.retries = 0
      const user = this.sock.user || {}
      this.phone = '+' + String(user.id || '').split(':')[0].split('@')[0]
      this.name = user.name || user.verifiedName || null
      this.setStatus('connected')
    }
    if (connection === 'close') {
      const code = lastDisconnect?.error?.output?.statusCode
      if (code === DisconnectReason.loggedOut) {
        // Appareil retiré depuis le téléphone : il faudra scanner un nouveau QR code
        this.clear()
        this.setStatus('logged_out', 'Le téléphone a déconnecté Jibiat : scannez un nouveau QR code.')
        return
      }
      if (this.stopped) return
      this.setStatus('disconnected', `Connexion interrompue (${code || 'réseau'}) : reconnexion…`)
      this.retries++
      setTimeout(() => this.start().catch((e) => log.error({ err: e, session: this.id }, 'reconnexion impossible')),
        Math.min(60000, 2000 * this.retries))
    }
  }

  setStatus (status, error = null) {
    const changed = status !== this.status || status === 'qr'
    this.status = status
    if (changed && status !== 'qr') {
      notify({ type: 'status', session: this.id, status, phone: this.phone, name: this.name, error })
    }
  }

  onMessage (msg) {
    const jid = msg.key?.remoteJid || ''
    if (msg.key?.fromMe || !msg.message || jid.endsWith('@g.us') || jid === 'status@broadcast' || jid.endsWith('@newsletter')) return
    // Numéro de l'expéditeur (les comptes récents peuvent arriver sous un identifiant masqué « @lid »)
    const pn = jid.endsWith('@s.whatsapp.net') ? jid : (msg.key.remoteJidAlt || msg.key.senderPn || msg.key.participantAlt || msg.key.participantPn || '')
    if (!pn.endsWith('@s.whatsapp.net')) return
    // Sans le numéro d'appareil éventuel (« 2250700000001:12@s.whatsapp.net »)
    const from = pn.split('@')[0].split(':')[0]

    const m = msg.message.ephemeralMessage?.message || msg.message
    const text = m.conversation || m.extendedTextMessage?.text || m.imageMessage?.caption || m.videoMessage?.caption ||
      m.buttonsResponseMessage?.selectedDisplayText || m.listResponseMessage?.title || m.templateButtonReplyMessage?.selectedDisplayText || null
    const kind = text !== null ? 'text' : (m.imageMessage ? 'image' : m.audioMessage ? 'audio' : m.documentMessage ? 'document' : m.locationMessage ? 'location' : 'other')

    notify({
      type: 'message',
      session: this.id,
      id: msg.key.id,
      from: '+' + from,
      name: msg.pushName || null,
      kind,
      text,
    })
  }

  onReceipt ({ key, update }) {
    if (!key?.fromMe || update?.status === undefined) return
    const id = this.sent.get(key.id)
    // 3 : remis au téléphone, 4 : lu (5 : écouté)
    const status = update.status >= 4 ? 'read' : update.status === 3 ? 'delivered' : null
    if (id && status) notify({ type: status, session: this.id, id })
  }

  /** Message mis dans la file : renvoie tout de suite son identifiant, l'envoi suit (événement sent ou failed) */
  enqueue (to, text) {
    const id = 'qr-' + crypto.randomUUID()
    this.queue = this.queue.then(() => this.deliver(id, to, text)).catch(() => {})
    return id
  }

  async deliver (id, to, text) {
    const wait = this.lastSend + MIN_DELAY + Math.floor(Math.random() * EXTRA_DELAY) - Date.now()
    if (wait > 0) await sleep(wait)
    this.lastSend = Date.now()
    try {
      if (this.status !== 'connected' || !this.sock) throw new Error('WhatsApp n\'est pas connecté : scannez le QR code dans Jibiat.')
      const number = String(to).replace(/\D/g, '')
      const [found] = await this.sock.onWhatsApp(number)
      if (!found?.exists) throw new Error('Ce numéro n\'a pas de compte WhatsApp.')
      await this.sock.presenceSubscribe(found.jid).catch(() => {})
      await this.sock.sendPresenceUpdate('composing', found.jid).catch(() => {})
      await sleep(800 + Math.min(4000, text.length * 25))
      await this.sock.sendPresenceUpdate('paused', found.jid).catch(() => {})
      const sent = await this.sock.sendMessage(found.jid, { text })
      if (sent?.key?.id) {
        this.sent.set(sent.key.id, id)
        if (this.sent.size > 5000) this.sent.delete(this.sent.keys().next().value)
      }
      notify({ type: 'sent', session: this.id, id })
    } catch (e) {
      notify({ type: 'failed', session: this.id, id, error: e.message || String(e) })
    }
  }

  async logout () {
    this.stopped = true
    try {
      await this.sock?.logout()
    } catch {
      this.sock?.end(undefined)
    }
    this.clear()
    this.setStatus('logged_out')
  }

  clear () {
    this.qr = null
    this.phone = null
    this.name = null
    fs.rmSync(this.dir, { recursive: true, force: true })
  }

  describe () {
    return { session: this.id, status: this.status, qr: this.qr, phone: this.phone, name: this.name }
  }
}

async function open (id) {
  let session = sessions.get(id)
  if (!session) {
    session = new Session(id)
    sessions.set(id, session)
  }
  if (!session.sock || session.status === 'logged_out') {
    session.status = 'starting'
    await session.start()
  }
  return session
}

// ---------------------------------------------------------------------------------------------------------------
// Événements vers Jibiat (signés avec le jeton)

async function notify (event, attempt = 1) {
  const body = JSON.stringify(event)
  const signature = crypto.createHmac('sha256', TOKEN).update(body).digest('hex')
  try {
    const res = await fetch(WEBHOOK, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Bridge-Signature': signature },
      body,
      signal: AbortSignal.timeout(15000),
    })
    if (res.status >= 500 && attempt < 5) throw new Error('HTTP ' + res.status)
  } catch (e) {
    if (attempt < 5) {
      setTimeout(() => notify(event, attempt + 1), 2000 * attempt)
    } else {
      log.error({ err: e, event: event.type, session: event.session }, 'événement non remis à Jibiat')
    }
  }
}

// ---------------------------------------------------------------------------------------------------------------
// API pour Jibiat (127.0.0.1 seulement)

const server = http.createServer(async (req, res) => {
  const reply = (status, data) => {
    res.writeHead(status, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify(data))
  }
  try {
    const auth = req.headers.authorization || ''
    const given = Buffer.from(auth.replace(/^Bearer /, ''))
    const expected = Buffer.from(TOKEN)
    if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) return reply(401, { message: 'Jeton invalide.' })

    if (req.method === 'GET' && req.url === '/health') return reply(200, { ok: true, sessions: sessions.size })

    const match = req.url.match(/^\/sessions\/([a-z0-9-]{1,40})(\/(start|send|logout))?$/)
    if (!match) return reply(404, { message: 'Adresse inconnue.' })
    const [, id, , action] = match

    if (req.method === 'GET' && !action) {
      const session = sessions.get(id)
      return reply(200, session ? session.describe() : { session: id, status: 'logged_out', qr: null, phone: null, name: null })
    }
    if (req.method === 'POST' && action === 'start') {
      const session = await open(id)
      // Laisse le temps au premier QR code d'arriver
      for (let i = 0; i < 20 && session.status === 'starting'; i++) await sleep(250)
      return reply(200, session.describe())
    }
    if (req.method === 'POST' && action === 'logout') {
      const session = sessions.get(id)
      if (session) await session.logout()
      sessions.delete(id)
      return reply(200, { session: id, status: 'logged_out' })
    }
    if (req.method === 'POST' && action === 'send') {
      const { to, text } = JSON.parse(await readBody(req) || '{}')
      const session = sessions.get(id)
      if (!session || session.status !== 'connected') return reply(409, { message: 'WhatsApp n\'est pas connecté : scannez le QR code dans Jibiat.' })
      if (!to || !text) return reply(422, { message: 'Destinataire et texte obligatoires.' })
      return reply(202, { id: session.enqueue(to, String(text)) })
    }
    return reply(405, { message: 'Méthode non autorisée.' })
  } catch (e) {
    log.error({ err: e }, 'requête en erreur')
    return reply(500, { message: e.message || 'Erreur du service WhatsApp.' })
  }
})

// Sessions déjà reliées : reprises au démarrage, sans nouveau QR code
for (const id of fs.readdirSync(DATA_DIR)) {
  if (/^[a-z0-9-]{1,40}$/.test(id) && fs.existsSync(path.join(DATA_DIR, id, 'creds.json'))) {
    open(id).catch((e) => log.error({ err: e, session: id }, 'session non reprise'))
  }
}

server.listen(PORT, '127.0.0.1', () => log.info({ port: PORT, webhook: WEBHOOK }, 'service WhatsApp prêt'))

// ---------------------------------------------------------------------------------------------------------------

function sleep (ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function readBody (req) {
  return new Promise((resolve, reject) => {
    let data = ''
    req.on('data', (chunk) => {
      data += chunk
      if (data.length > 100000) req.destroy()
    })
    req.on('end', () => resolve(data))
    req.on('error', reject)
  })
}

/** Lecture simple d'un fichier .env (clé=valeur, guillemets retirés) */
function readEnv (file) {
  const values = {}
  if (!fs.existsSync(file)) return values
  for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
    if (m) values[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2')
  }
  return values
}
