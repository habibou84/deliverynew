// Application au nom d'une entreprise (console › Applications à leur nom, workflow branded-app.yml) :
// applique à mobile/<application> le nom, l'identifiant et la couleur de la fiche de la version, et télécharge
// l'icône de l'entreprise (ensuite : python3 mobile/assets.py --app <application> --source <icône>).
//
//   node mobile/brand.mjs --app ecommercant --manifest fiche.json [--icon icone.png]
//
// Écrit sur la sortie standard, en JSON, l'entreprise intégrée (VITE_BRANDED_COMPANY) et le chemin de l'icône.
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = dirname(fileURLToPath(import.meta.url))
const APPS = ['ecommercant', 'livreur']

function option(name) {
  const index = process.argv.indexOf(`--${name}`)
  return index > -1 ? process.argv[index + 1] : undefined
}

function fail(message) {
  console.error(`brand.mjs : ${message}`)
  process.exit(1)
}

function escapeXml(text) {
  return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/'/g, "\\'").replace(/"/g, '\\"')
}

const app = option('app')
if (!APPS.includes(app)) fail(`--app ${APPS.join(' ou ')} attendu`)
const file = option('manifest')
if (!file) fail('--manifest attendu')
const raw = JSON.parse(readFileSync(file, 'utf8'))
const manifest = raw.data || raw
const target = manifest.apps?.[app]
if (!target?.app_id || !/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$/.test(target.app_id)) fail(`identifiant de l'application ${app} invalide`)
if (!target.name) fail(`nom de l'application ${app} absent`)
const color = /^#[0-9a-fA-F]{6}$/.test(target.color || '') ? target.color : '#047857'
const folder = join(ROOT, app)

// Capacitor : nom, identifiant et couleur de fond
const configPath = join(folder, 'capacitor.config.json')
const config = JSON.parse(readFileSync(configPath, 'utf8'))
config.appId = target.app_id
config.appName = target.name
config.android = { ...(config.android || {}), backgroundColor: color }
config.ios = { ...(config.ios || {}), backgroundColor: color }
writeFileSync(configPath, `${JSON.stringify(config, null, 4)}\n`)

// Android : nom sous l'icône et schéma d'adresse propre à l'application (l'identifiant passe par Gradle : -PappId)
const strings = join(folder, 'android', 'app', 'src', 'main', 'res', 'values', 'strings.xml')
writeFileSync(strings, `<?xml version='1.0' encoding='utf-8'?>
<resources>
    <string name="app_name">${escapeXml(target.name)}</string>
    <string name="title_activity_main">${escapeXml(target.name)}</string>
    <string name="package_name">${target.app_id}</string>
    <string name="custom_url_scheme">${target.app_id}</string>
</resources>
`)

// Icône de l'entreprise (carrée, symbole dans la zone sûre)
let icon = option('icon')
if (!icon && target.icon_url) {
  const response = await fetch(target.icon_url)
  if (!response.ok) fail(`icône introuvable (${response.status}) : ${target.icon_url}`)
  icon = join(folder, 'brand-icon.png')
  writeFileSync(icon, Buffer.from(await response.arrayBuffer()))
}

const company = manifest.company || {}
process.stdout.write(`${JSON.stringify({
  company: { code: company.code, name: company.name, url: company.url, logo_url: company.logo_url ?? null },
  icon: icon ? resolve(icon) : null,
  app_id: target.app_id,
  name: target.name,
})}\n`)
