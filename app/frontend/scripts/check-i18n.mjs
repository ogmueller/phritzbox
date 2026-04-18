import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, join } from 'path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const en = JSON.parse(readFileSync(join(__dirname, '../src/i18n/en.json'), 'utf-8'))
const de = JSON.parse(readFileSync(join(__dirname, '../src/i18n/de.json'), 'utf-8'))

const enKeys = Object.keys(en).sort()
const deKeys = Object.keys(de).sort()
const missingInDe = enKeys.filter((k) => !deKeys.includes(k))
const missingInEn = deKeys.filter((k) => !enKeys.includes(k))

let ok = true
if (missingInDe.length) {
  console.error('Missing in de.json:', missingInDe)
  ok = false
}
if (missingInEn.length) {
  console.error('Missing in en.json:', missingInEn)
  ok = false
}

if (ok) {
  console.log(`OK: ${enKeys.length} keys in sync`)
} else {
  process.exit(1)
}
