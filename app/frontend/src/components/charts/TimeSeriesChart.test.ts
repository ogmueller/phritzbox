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
    })
  })

  it('resolves ties to the earliest point', () => {
    const data = [pt('2026-08-01T00:00:00', 5), pt('2026-08-01T01:00:00', 5)]
    expect(computeExtremes(data)).toEqual({
      min: pt('2026-08-01T00:00:00', 5),
      max: pt('2026-08-01T00:00:00', 5),
    })
  })

  it('handles negative values', () => {
    const data = [pt('2026-08-01T00:00:00', -3), pt('2026-08-01T01:00:00', -8), pt('2026-08-01T02:00:00', -1)]
    expect(computeExtremes(data)).toEqual({
      min: pt('2026-08-01T01:00:00', -8),
      max: pt('2026-08-01T02:00:00', -1),
    })
  })
})
