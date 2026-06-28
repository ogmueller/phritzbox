import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor, act } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'

const getStats = vi.fn()
const refreshStats = vi.fn()
const getStatTypes = vi.fn()

vi.mock('../api/stats', () => ({
  getStats: (...a: unknown[]) => getStats(...a),
  refreshStats: (...a: unknown[]) => refreshStats(...a),
  getStatTypes: (...a: unknown[]) => getStatTypes(...a),
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

  it('does not auto-run when nothing was saved', async () => {
    await act(async () => { render(<ReportsPage />) })
    expect(getStats).not.toHaveBeenCalled()
  })
})
