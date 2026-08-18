import { describe, it, expect } from 'vitest'
import { formatCurrency, formatNumber, formatEnergy, formatWatts, parseDecimal, EM_DASH } from './format'

// Non-breaking and narrow-no-break spaces appear in Intl output; normalise so
// assertions read as the human-visible string.
const norm = (s: string) => s.replace(/[\u00a0\u202f]/g, ' ')

describe('formatCurrency', () => {
  it('follows the locale for separator and symbol placement', () => {
    // The reason this helper exists: the inline house style would emit "20.6 €"
    // for both, which is wrong for each in a different way.
    expect(norm(formatCurrency(20.6, 'EUR', 'de'))).toBe('20,60 €')
    expect(norm(formatCurrency(20.6, 'EUR', 'en'))).toBe('€20.60')
  })

  it('renders an em dash rather than zero when the amount is unknown', () => {
    // null means "no tariff configured" — never "it is free".
    expect(formatCurrency(null, 'EUR', 'de')).toBe(EM_DASH)
    expect(formatCurrency(undefined, 'EUR', 'de')).toBe(EM_DASH)
  })

  it('formats a real zero as zero', () => {
    // A configured price of 0 is legitimate (own generation) and must not be
    // confused with "not configured".
    expect(norm(formatCurrency(0, 'EUR', 'de'))).toBe('0,00 €')
  })

  it('falls back instead of throwing on an invalid currency code', () => {
    // Intl.NumberFormat throws RangeError here, which would blank the page.
    expect(() => formatCurrency(12.5, 'NOPE', 'en')).not.toThrow()
    expect(norm(formatCurrency(12.5, 'NOPE', 'en'))).toBe('12.5 NOPE')
  })

  it('handles every currency the backend allows', () => {
    for (const code of ['EUR', 'CHF', 'GBP', 'USD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK']) {
      expect(() => formatCurrency(1, code, 'de')).not.toThrow()
      expect(formatCurrency(1, code, 'de')).not.toBe(EM_DASH)
    }
  })
})

describe('formatEnergy', () => {
  it('stays in Wh below a thousand', () => {
    expect(norm(formatEnergy(500, 'en'))).toBe('500 Wh')
    expect(norm(formatEnergy(999, 'en'))).toBe('999 Wh')
  })

  it('switches to kWh at a thousand, matching the chart axis', () => {
    expect(norm(formatEnergy(1000, 'en'))).toBe('1 kWh')
    expect(norm(formatEnergy(18864, 'en'))).toBe('18.86 kWh')
  })

  it('uses the locale decimal separator', () => {
    expect(norm(formatEnergy(18864, 'de'))).toBe('18,86 kWh')
  })

  it('renders an em dash for unknown', () => {
    expect(formatEnergy(null, 'en')).toBe(EM_DASH)
  })
})

describe('formatWatts', () => {
  it('keeps two decimals for small standby draws', () => {
    // A 0.5 W vampire load is the interesting case; rounding it to 1 W or 0 W
    // would defeat the point.
    expect(norm(formatWatts(0.48, 'en'))).toBe('0.48 W')
  })

  it('drops to one decimal for larger draws', () => {
    expect(norm(formatWatts(122.74, 'en'))).toBe('122.7 W')
  })

  it('renders an em dash for unknown, not 0 W', () => {
    expect(formatWatts(null, 'en')).toBe(EM_DASH)
    // A measured zero is a real reading — a switched-off outlet — and reads as
    // plain "0 W", distinct from the em dash used for "not known".
    expect(norm(formatWatts(0, 'en'))).toBe('0 W')
  })
})

describe('formatNumber', () => {
  it('groups thousands per locale', () => {
    expect(norm(formatNumber(1234567, 'en', 0))).toBe('1,234,567')
    expect(norm(formatNumber(1234567, 'de', 0))).toBe('1.234.567')
  })
})

describe('parseDecimal', () => {
  it('reads a decimal comma, which is what the German UI asks for', () => {
    expect(parseDecimal('0,35')).toBe(0.35)
  })

  it('still reads a decimal point', () => {
    expect(parseDecimal('0.35')).toBe(0.35)
  })

  it('treats the last separator as the decimal one', () => {
    expect(parseDecimal('1.234,56')).toBe(1234.56)
    expect(parseDecimal('1,234.56')).toBe(1234.56)
  })

  it('ignores surrounding space', () => {
    expect(parseDecimal(' 0,35 ')).toBe(0.35)
  })

  it('returns null for blank and for anything that is not a number', () => {
    // Blank is not zero: an empty price field means "no tariff", and the caller
    // has to be able to tell the two apart.
    expect(parseDecimal('')).toBeNull()
    expect(parseDecimal('   ')).toBeNull()
    expect(parseDecimal('cheap')).toBeNull()
  })

  it('reads a plain zero as zero', () => {
    expect(parseDecimal('0')).toBe(0)
  })
})
