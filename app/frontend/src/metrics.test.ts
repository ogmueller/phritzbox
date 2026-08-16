import { describe, it, expect } from 'vitest'
import { METRICS, metric, metricUnit, DEFAULT_METRIC } from './metrics'
import en from './i18n/en.json'
import de from './i18n/de.json'

describe('metric registry', () => {
  it('covers every type the backend MetricUnits declares', () => {
    // Mirror of app/src/Service/MetricUnits.php TYPES. If the backend gains a
    // metric and this list is not updated, the UI silently cannot chart or
    // alert on it — which is exactly how the three old duplicated lists drifted.
    expect(METRICS.map((m) => m.value).sort()).toEqual(
      ['battery', 'energy', 'power', 'presence', 'temperature', 'voltage'],
    )
  })

  it('has a translation for every label in both locales', () => {
    for (const m of METRICS) {
      expect(en, `missing en ${m.labelKey}`).toHaveProperty(m.labelKey)
      expect(de, `missing de ${m.labelKey}`).toHaveProperty(m.labelKey)
    }
  })

  it('gives every metric a distinct colour', () => {
    const colors = METRICS.map((m) => m.color)
    expect(new Set(colors).size).toBe(colors.length)
  })

  it('resolves units, including the deliberately empty presence unit', () => {
    expect(metricUnit('temperature')).toBe('°C')
    expect(metricUnit('battery')).toBe('%')
    expect(metricUnit('presence')).toBe('')
  })

  it('falls back rather than returning undefined for an unknown type', () => {
    // A stale persisted filter must not blank the Reports page.
    expect(metricUnit('nonsense')).toBe('')
    expect(metric('nonsense').value).toBe(METRICS[0].value)
  })

  it('defaults to a metric that exists', () => {
    expect(METRICS.some((m) => m.value === DEFAULT_METRIC)).toBe(true)
  })
})
