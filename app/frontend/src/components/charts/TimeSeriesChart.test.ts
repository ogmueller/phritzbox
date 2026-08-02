import { describe, it, expect } from 'vitest'
import { formatAxisTime } from './TimeSeriesChart'

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
