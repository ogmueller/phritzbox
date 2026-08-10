/**
 * Time ranges for the charting pages.
 *
 * A window is *rolling* — it ends at `now` rather than covering a calendar
 * span — so a chart is always full width and never half empty just after
 * midnight. Reports keeps `from`/`to` as local `YYYY-MM-DD` (that is what the
 * native date pickers speak); `resolveRange` turns that state plus the active
 * preset into the absolute instants actually sent to the API. Device detail has
 * no picker and takes a fixed window straight from `rollingRange`.
 */

export const HOUR_MS = 60 * 60 * 1000

/**
 * Local calendar date (YYYY-MM-DD). toISOString() would format in UTC, which
 * rolls to the wrong day just after local midnight for users ahead of UTC.
 */
export function isoDate(d: Date): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

/**
 * 'YYYY-MM-DD' → a local Date. new Date('2026-08-02') parses as UTC midnight,
 * which lands on the previous day for anyone behind UTC.
 */
export function localDate(s: string, h = 0, m = 0, sec = 0): Date {
  const [y, mo, d] = s.split('-').map(Number)
  return new Date(y, mo - 1, d, h, m, sec)
}

/**
 * Offset-bearing ISO instant, e.g. "2026-08-09T14:32:00+02:00". The server
 * converts it to its own timezone before comparing against stored readings, so
 * a window covers the same moment wherever browser and server happen to sit.
 */
export function isoInstant(d: Date): string {
  const p = (n: number) => String(n).padStart(2, '0')
  const offsetMin = -d.getTimezoneOffset()
  const sign = offsetMin >= 0 ? '+' : '-'
  const abs = Math.abs(offsetMin)
  return `${isoDate(d)}T${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
    + `${sign}${p(Math.floor(abs / 60))}:${p(abs % 60)}`
}

/** `hours` = how far back the window starts from now. */
export const PRESETS = [
  { key: 'last24h',    labelKey: 'reports.last24h'    as const, hours: 24 },
  { key: 'last48h',    labelKey: 'reports.last48h'    as const, hours: 48 },
  { key: 'last7Days',  labelKey: 'reports.last7Days'  as const, hours: 24 * 7 },
  { key: 'last30Days', labelKey: 'reports.last30Days' as const, hours: 24 * 30 },
]

export const DEFAULT_PRESET_KEY = 'last7Days'

// Preset keys written by earlier versions. "Today" and "Yesterday" were never
// calendar days — they resolved to "since midnight" and "since yesterday
// midnight" — so they map onto the rolling windows that replaced them.
const LEGACY_PRESET_KEYS: Record<string, string> = { today: 'last24h', yesterday: 'last48h' }

/** null stays null (a deliberate custom range); an unknown key falls back to the default. */
export function normalisePresetKey(key: string | null | undefined): string | null {
  if (key === null || key === undefined) return null
  const mapped = LEGACY_PRESET_KEYS[key] ?? key
  return PRESETS.some((p) => p.key === mapped) ? mapped : DEFAULT_PRESET_KEY
}

/** Local calendar dates spanned by a rolling window — what the custom pickers show. */
export function presetDates(hours: number): { from: string; to: string } {
  const now = new Date()
  return { from: isoDate(new Date(now.getTime() - hours * HOUR_MS)), to: isoDate(now) }
}

/** The absolute window covering the last `hours` hours, ending now. */
export function rollingRange(hours: number): { from: string; to: string } {
  const now = new Date()
  return { from: isoInstant(new Date(now.getTime() - hours * HOUR_MS)), to: isoInstant(now) }
}

/**
 * UI state → the absolute window to query. A preset resolves against *now*; a
 * custom range covers whole local days, which is what the date pickers imply.
 */
export function resolveRange(presetKey: string | null, fromDate: string, toDate: string): { from: string; to: string } {
  const preset = PRESETS.find((p) => p.key === presetKey)
  if (preset) {
    return rollingRange(preset.hours)
  }
  return { from: isoInstant(localDate(fromDate)), to: isoInstant(localDate(toDate, 23, 59, 59)) }
}
