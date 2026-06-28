import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'

const getStats = vi.fn()
const refreshStats = vi.fn()
const getStatTypes = vi.fn()
const getReportAlertEvents = vi.fn()

vi.mock('../api/stats', () => ({
  getStats: (...a: unknown[]) => getStats(...a),
  refreshStats: (...a: unknown[]) => refreshStats(...a),
  getStatTypes: (...a: unknown[]) => getStatTypes(...a),
  getReportAlertEvents: (...a: unknown[]) => getReportAlertEvents(...a),
}))

vi.mock('../contexts/DeviceContext', () => ({
  useDeviceContext: () => ({
    devices: [{ ain: 'a1', name: 'Dev1' }, { ain: 'a2', name: 'Dev2' }],
    loading: false,
    error: null,
    refresh: vi.fn(),
  }),
}))

// Avoid pulling ECharts into jsdom.
vi.mock('../components/charts/TimeSeriesChart', () => ({
  TimeSeriesChart: () => null,
  getAvgStyle: () => ({ name: 'avg', color: '#000' }),
  selectAveragePeriods: () => [],
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k, i18n: { language: 'en' } }),
}))

const KEY = 'phritzbox_reports_filter'

describe('ReportsPage filter persistence', () => {
  beforeEach(() => {
    localStorage.clear()
    getStats.mockReset().mockResolvedValue({ ain: 'a2', type: 'power', data: [] })
    getReportAlertEvents.mockReset().mockResolvedValue([])
  })

  it('restores a saved filter and auto-runs the query on return', async () => {
    localStorage.setItem(KEY, JSON.stringify({
      ain: 'a2', type: 'power', presetKey: 'yesterday',
      from: '2020-01-01', to: '2020-01-02', fitToData: false, enabledPeriods: [],
    }))

    render(<ReportsPage />)

    await waitFor(() => expect(getStats).toHaveBeenCalled())
    const [ain, type, from] = getStats.mock.calls[0]
    expect(ain).toBe('a2')
    expect(type).toBe('power')
    // The "yesterday" preset must re-resolve relative to today, not reuse the stale stored date.
    expect(from).not.toBe('2020-01-01')
  })

  it('auto-loads the default device on a fresh visit (no Load button)', async () => {
    render(<ReportsPage />)
    await waitFor(() => expect(getStats).toHaveBeenCalled())
    expect(getStats.mock.calls[0][0]).toBe('a1')
  })

  it('restores a second device and alert-event overlay', async () => {
    localStorage.setItem(KEY, JSON.stringify({
      ain: 'a2', ain2: 'a1', type: 'temperature', presetKey: null,
      from: '2026-06-01', to: '2026-06-02', fitToData: true, showEvents: true, enabledPeriods: [],
    }))

    render(<ReportsPage />)

    await waitFor(() => expect(getReportAlertEvents).toHaveBeenCalled())
    // both devices fetched
    const fetchedAins = getStats.mock.calls.map((c) => c[0])
    expect(fetchedAins).toContain('a2')
    expect(fetchedAins).toContain('a1')
    // events requested for both selected devices
    const [, , , devices] = getReportAlertEvents.mock.calls[0]
    expect(devices).toEqual(expect.arrayContaining(['a2', 'a1']))
  })
})
