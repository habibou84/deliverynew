// Mise à jour sans coupure : recopie dans le nouveau dossier de l'interface les fichiers de la
// version en ligne (ceux de son manifest.json). Les pages déjà ouvertes chargent encore leurs
// morceaux d'interface après la bascule ; les fichiers des versions plus anciennes disparaissent.
//
//   node deploy/carry-assets.mjs public/build public/build-next

import { copyFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'

const [from, to] = process.argv.slice(2)
const manifest = join(from, 'manifest.json')
if (!existsSync(manifest)) process.exit(0)

const files = new Set()
for (const entry of Object.values(JSON.parse(readFileSync(manifest, 'utf8')))) {
  for (const file of [entry.file, ...(entry.css || []), ...(entry.assets || [])]) {
    if (file) files.add(file)
  }
}

let copied = 0
for (const file of files) {
  const source = join(from, file)
  if (!existsSync(source)) continue
  mkdirSync(dirname(join(to, file)), { recursive: true })
  copyFileSync(source, join(to, file))
  copied++
}
console.log(`    ${copied} fichier(s) de la version en ligne gardé(s)`)
