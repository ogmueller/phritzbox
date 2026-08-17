import { describe, it, expect } from 'vitest'
import { computeExtremes, formatAxisTime } from './TimeSeriesChart'

const DAY = 86_400_000
const at = (iso: string) => new Date(iso).getTime()

describe('formatAxisTime', () => {
  it('labels midnight as 00:00 on a day-range chart', () => {
    expect(formatAxisTime(at('2026-08-02T00:00:00'), DAY, 'en-US')).toBe('00:00')
    expect(formatAxisTime(at('2026-08-01T20:00:00'), DAY, 'en-US')).toBe('20:00')
    expect(formatAxisTime(at('2026-08-02T04:00:00'), DAY, 'en-US')).toBe('04:00')
  })

  it('marks day starts with the date on a week-range chart', () => {
    expect(formatAxisTime(at('2026-08-02T00:00:00'), 7 * DAY, 'en-US')).toBe('Aug 2')
    expect(formatAxisTime(at('2026-08-02T12:00:00'), 7 * DAY, 'en-US')).toBe('12:00')
  })

  it('uses dates only beyond two months', () => {
    expect(formatAxisTime(at('2026-08-02T00:00:00'), 90 * DAY, 'en-US')).toBe('Aug 2')
    expect(formatAxisTime(at('2026-08-02T06:00:00'), 90 * DAY, 'en-US')).toBe('Aug 2')
  })

  it('uses month and year beyond a year', () => {
    expect(formatAxisTime(at('2026-08-02T00:00:00'), 500 * DAY, 'en-US')).toBe('Aug 2026')
  })
})

describe('computeExtremes', () => {
  const pt = (time: string, value: number) => ({ time, value })

  it('returns null for an empty series', () => {
    expect(computeExtremes([])).toBeNull()
  })

  it('finds the highest and lowest reading with their timestamps', () => {
    const data = [
      pt('2026-08-01T00:00:00', 21.5),
      pt('2026-08-01T01:00:00', 19.0),
      pt('2026-08-01T02:00:00', 24.25),
      pt('2026-08-01T03:00:00', 22.0),
    ]
    expect(computeExtremes(data)).toEqual({
      min: pt('2026-08-01T01:00:00', 19.0),
      max: pt('2026-08-01T02:00:00', 24.25),
      // Raw points carry no bucket extremes, so the levels are the values.
      minLevel: 19.0,
      maxLevel: 24.25,
    })
  })

  it('resolves ties to the earliest point', () => {
    const data = [pt('2026-08-01T00:00:00', 5), pt('2026-08-01T01:00:00', 5)]
    expect(computeExtremes(data)).toEqual({
      min: pt('2026-08-01T00:00:00', 5),
      max: pt('2026-08-01T00:00:00', 5),
      minLevel: 5,
      maxLevel: 5,
    })
  })

  it('handles negative values', () => {
    const data = [pt('2026-08-01T00:00:00', -3), pt('2026-08-01T01:00:00', -8), pt('2026-08-01T02:00:00', -1)]
    expect(computeExtremes(data)).toEqual({
      min: pt('2026-08-01T01:00:00', -8),
      max: pt('2026-08-01T02:00:00', -1),
      minLevel: -8,
      maxLevel: -1,
    })
  })

  it('reports the true bucket extremes on an aggregated series', () => {
    // A 2000 W spike inside one bucket averages down to 140 W. Reporting the
    // highest average as the maximum would understate it by more than 10x.
    const data = [
      { time: '2026-08-01T00:00:00', value: 140, min: 3, max: 2000 },
      { time: '2026-08-01T01:00:00', value: 150, min: 10, max: 180 },
    ]
    const ext = computeExtremes(data)
    expect(ext?.maxLevel).toBe(2000)
    expect(ext?.minLevel).toBe(3)
    // The dot still belongs on a plotted vertex, not floating at 2000.
    expect(ext?.max.value).toBe(150)
    expect(ext?.min.value).toBe(140)
  })

  it('mixes aggregated and raw points without losing either', () => {
    const data = [
      { time: '2026-08-01T00:00:00', value: 10, min: 1, max: 99 },
      { time: '2026-08-01T01:00:00', value: 50 },
    ]
    const ext = computeExtremes(data)
    expect(ext?.maxLevel).toBe(99)
    expect(ext?.minLevel).toBe(1)
  })
})
