/**
 * Locale-aware formatting for money and energy.
 *
 * The house style elsewhere is an inline `` `${Number(v.toFixed(2))} ${unit}` ``,
 * which is fine for a metric unit but wrong for currency: it would render
 * `20.6 €` for both English and German, where the correct forms are `€20.60` and
 * `20,60 €`. Money needs the locale to decide the separator, the precision and
 * which side the symbol goes on, so it goes through here instead.
 *
 * Deliberately narrow in scope: money and the new kWh figures only. Existing
 * readouts keep their inline formatting — retrofitting them is a separate change.
 */

/** Shown wherever a value is genuinely unknown, so it can never read as zero. */
export const EM_DASH = '—'

// Intl.NumberFormat construction is not free and these render inside tables.
const currencyFormatters = new Map<string, Intl.NumberFormat>()
const numberFormatters = new Map<string, Intl.NumberFormat>()

function currencyFormatter(locale: string, currency: string): Intl.NumberFormat | null {
  const key = `${locale}|${currency}`
  const cached = currencyFormatters.get(key)
  if (cached) return cached

  try {
    const formatter = new Intl.NumberFormat(locale, { style: 'currency', currency })
    currencyFormatters.set(key, formatter)
    return formatter
  } catch {
    // An unknown currency code throws RangeError, which would take the whole
    // page down. The server only accepts an allowlist, so this is the second
    // line of defence rather than the first.
    return null
  }
}

function numberFormatter(locale: string, maxFrac: number): Intl.NumberFormat {
  const key = `${locale}|${maxFrac}`
  const cached = numberFormatters.get(key)
  if (cached) return cached

  const formatter = new Intl.NumberFormat(locale, { maximumFractionDigits: maxFrac })
  numberFormatters.set(key, formatter)
  return formatter
}

/**
 * Money, or an em dash when the amount is unknown.
 *
 * `null` means "no tariff configured" throughout the app and must never render
 * as `0.00` — a household that has not entered a price has not been told its
 * electricity is free.
 */
export function formatCurrency(value: number | null | undefined, currency: string, locale: string): string {
  if (value === null || value === undefined) return EM_DASH

  const formatter = currencyFormatter(locale, currency)

  return formatter
    ? formatter.format(value)
    : `${numberFormatter(locale, 2).format(value)} ${currency}`
}

export function formatNumber(value: number, locale: string, maxFrac = 2): string {
  return numberFormatter(locale, maxFrac).format(value)
}

/**
 * A number typed by hand, in either decimal convention — the inverse of the
 * formatters above.
 *
 * The German UI asks for "0,35" in so many words, and `Number('0,35')` is NaN,
 * so anything reading a typed amount has to accept the comma or silently do
 * nothing. Mirrors `SettingsController::normalizeDecimal`: the last separator in
 * the string is the decimal one, the rest is thousands grouping.
 *
 * @returns null when the input is blank or not a number
 */
export function parseDecimal(input: string): number | null {
  const value = input.trim()
  if (value === '') return null

  const comma = value.lastIndexOf(',')
  const dot = value.lastIndexOf('.')
  const normalized = comma === -1
    ? value
    : comma > dot
      ? value.replace(/\./g, '').replace(',', '.')
      : value.replace(/,/g, '')

  const parsed = Number(normalized)

  return Number.isFinite(parsed) ? parsed : null
}

/**
 * Watt-hours, switching to kWh once the figure gets long.
 *
 * Matches the chart's own Wh→kWh threshold so a total under a chart does not
 * disagree with the axis above it.
 */
export function formatEnergy(wh: number | null | undefined, locale: string): string {
  if (wh === null || wh === undefined) return EM_DASH
  if (Math.abs(wh) >= 1000) {
    return `${formatNumber(wh / 1000, locale, 2)} kWh`
  }

  return `${formatNumber(wh, locale, 0)} Wh`
}

export function formatWatts(watts: number | null | undefined, locale: string): string {
  if (watts === null || watts === undefined) return EM_DASH

  // Sub-watt standby draws are real and interesting, so keep two decimals there.
  return `${formatNumber(watts, locale, watts < 10 ? 2 : 1)} W`
}
