import { describe, it, expect, vi, afterEach } from 'vitest'
import { PRESETS, resolveRange, rollingRange } from './timeRange'

const HOUR_MS = 60 * 60 * 1000

// 2026-08-09 14:32:00 local. Built from parts so the test holds in any timezone.
const NOW = new Date(2026, 7, 9, 14, 32, 0)

function freeze() {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
}

afterEach(() => {
  vi.useRealTimers()
})

describe('rollingRange', () => {
  it('ends at now and spans the requested hours', () => {
    freeze()
    const { from, to } = rollingRange(24 * 7)
    expect(new Date(to).getTime()).toBe(NOW.getTime())
    expect(new Date(to).getTime() - new Date(from).getTime()).toBe(7 * 24 * HOUR_MS)
  })
})

describe('resolveRange', () => {
  it('anchors every preset to now', () => {
    freeze()
    for (const preset of PRESETS) {
      const { from, to } = resolveRange(preset.key, '2020-01-01', '2020-01-02')
      expect(new Date(to).getTime(), preset.key).toBe(NOW.getTime())
      expect(new Date(to).getTime() - new Date(from).getTime(), preset.key)
        .toBe(preset.hours * HOUR_MS)
    }
  })

  it('ignores the custom dates while a preset is active', () => {
    freeze()
    expect(resolveRange('last24h', '2020-01-01', '2020-01-02'))
      .toEqual(resolveRange('last24h', '2019-06-06', '2019-06-07'))
  })

  it('covers whole local days for a custom range', () => {
    const { from, to } = resolveRange(null, '2026-08-02', '2026-08-09')
    expect(new Date(from).getTime()).toBe(new Date(2026, 7, 2, 0, 0, 0).getTime())
    expect(new Date(to).getTime()).toBe(new Date(2026, 7, 9, 23, 59, 59).getTime())
  })

  it('emits an offset-bearing instant, not a UTC-shifted date', () => {
    const { from } = resolveRange(null, '2026-08-02', '2026-08-02')
    expect(from).toMatch(/^2026-08-02T00:00:00[+-]\d{2}:\d{2}$/)
  })

  it('treats an unknown preset key as a custom range', () => {
    const { from } = resolveRange('nope', '2026-08-02', '2026-08-09')
    expect(new Date(from).getTime()).toBe(new Date(2026, 7, 2, 0, 0, 0).getTime())
  })

  it('resolves a single-day custom range to that whole day', () => {
    const { from, to } = resolveRange(null, '2026-04-15', '2026-04-15')
    expect(new Date(to).getTime() - new Date(from).getTime()).toBe(24 * HOUR_MS - 1000)
  })
})
