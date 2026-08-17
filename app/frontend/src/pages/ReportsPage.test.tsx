import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, waitFor, screen, fireEvent } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'

const getStats = vi.fn()
const refreshStats = vi.fn()
const getStatTypes = vi.fn()
const getReportAlertEvents = vi.fn()
const exportStats = vi.fn()

vi.mock('../api/stats', () => ({
  getStats: (...a: unknown[]) => getStats(...a),
  refreshStats: (...a: unknown[]) => refreshStats(...a),
  getStatTypes: (...a: unknown[]) => getStatTypes(...a),
  getReportAlertEvents: (...a: unknown[]) => getReportAlertEvents(...a),
  exportStats: (...a: unknown[]) => exportStats(...a),
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
  selectAveragePeriods: () => ['day'],
}))

// Keys pass through, but interpolated values are appended so assertions can see
// what went into a composed string such as the chart title.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, vars?: Record<string, unknown>) => (vars ? `${k} ${Object.values(vars).join(' | ')}` : k),
    i18n: { language: 'en' },
  }),
}))

const KEY = 'phritzbox_reports_filter'

describe('ReportsPage filter persistence', () => {
  beforeEach(() => {
    localStorage.clear()
    getStats.mockReset().mockResolvedValue({ ain: 'a2', type: 'power', data: [] })
    getReportAlertEvents.mockReset().mockResolvedValue([])
  })

  it('migrates the legacy "yesterday" preset to a rolling 48h window', async () => {
    const now = new Date(2026, 7, 9, 14, 32, 0)
    vi.useFakeTimers()
    vi.setSystemTime(now)
    localStorage.setItem(KEY, JSON.stringify({
      ain: 'a2', type: 'power', presetKey: 'yesterday',
      from: '2020-01-01', to: '2020-01-02', fitToData: false, enabledPeriods: [],
    }))

    render(<ReportsPage />)

    await vi.waitFor(() => expect(getStats).toHaveBeenCalled())
    const [ain, type, from, to] = getStats.mock.calls[0]
    expect(ain).toBe('a2')
    expect(type).toBe('power')
    // "yesterday" mapped onto last48h, re-resolved against now — not the stale
    // stored dates. Compare instants so the assertion holds in any timezone.
    expect(new Date(to).getTime()).toBe(now.getTime())
    expect(new Date(to).getTime() - new Date(from).getTime()).toBe(48 * 60 * 60 * 1000)
    vi.useRealTimers()
  })

  it('slides a rolling preset window forward when the data is pulled again', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const t0 = new Date(2026, 7, 9, 14, 0, 0)
    const twoHours = 2 * 60 * 60 * 1000
    vi.setSystemTime(t0)
    refreshStats.mockReset().mockResolvedValue({ status: 'ok' })
    localStorage.setItem(KEY, JSON.stringify({
      ain: 'a2', type: 'power', presetKey: 'last48h',
      from: '2020-01-01', to: '2020-01-02', fitToData: false, enabledPeriods: [],
    }))
    getStats.mockResolvedValue({ ain: 'a2', type: 'power', data: [{ time: t0.toISOString(), value: 1, type: 'power' }] })

    render(<ReportsPage />)
    await waitFor(() => expect(getStats).toHaveBeenCalled())
    // Wait for the chart to appear: the refresh is a no-op until a load succeeded.
    await waitFor(() => expect(screen.queryByText(/reports.chartTitle/)).not.toBeNull())

    // Two hours pass with the page open, then the user pulls fresh data.
    vi.setSystemTime(new Date(t0.getTime() + twoHours))
    fireEvent.click(screen.getByText('reports.refresh'))
    await waitFor(() => expect(getStats.mock.calls.length).toBeGreaterThan(1))

    // Both bounds move with the clock — the window stays 48h wide but now ends
    // at the new "now" instead of the instant the page was loaded.
    const [, , from, to] = getStats.mock.calls[getStats.mock.calls.length - 1]
    expect(new Date(to as string).getTime()).toBe(t0.getTime() + twoHours)
    expect(new Date(to as string).getTime() - new Date(from as string).getTime()).toBe(48 * 60 * 60 * 1000)
    vi.useRealTimers()
  })

  it('names the resolved window in the chart title and moves it on a pull', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const t0 = new Date(2026, 7, 9, 14, 0, 0)
    vi.setSystemTime(t0)
    refreshStats.mockReset().mockResolvedValue({ status: 'ok' })
    localStorage.setItem(KEY, JSON.stringify({
      ain: 'a2', type: 'power', presetKey: 'last48h', from: '2020-01-01', to: '2020-01-02',
    }))
    getStats.mockResolvedValue({ ain: 'a2', type: 'power', data: [{ time: t0.toISOString(), value: 1, type: 'power' }] })

    render(<ReportsPage />)

    // The title carries the instants, not the preset's name — that name reads
    // the same before and after a pull and would only make the title longer.
    const title = await screen.findByText(/reports\.chartTitle/)
    expect(title.textContent).toContain('Aug 7, 14:00 – Aug 9, 14:00')
    expect(title.textContent).not.toContain('reports.last48h')

    vi.setSystemTime(new Date(t0.getTime() + 2 * 60 * 60 * 1000))
    fireEvent.click(screen.getByText('reports.refresh'))

    await waitFor(() =>
      expect(screen.getByText(/reports\.chartTitle/).textContent)
        .toContain('Aug 7, 16:00 – Aug 9, 16:00'))
    vi.useRealTimers()
  })

  it('keeps an average the user switched off when data is pulled', async () => {
    refreshStats.mockReset().mockResolvedValue({ status: 'ok' })
    getStats.mockResolvedValue({
      ain: 'a1',
      type: 'temperature',
      data: [{ time: '2026-08-09T12:00:00Z', value: 1, type: 'temperature' }, { time: '2026-08-09T13:00:00Z', value: 2, type: 'temperature' }],
    })

    render(<ReportsPage />)

    const chip = await screen.findByRole('button', { name: 'avg' })
    expect(chip).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(chip)
    expect(chip).toHaveAttribute('aria-pressed', 'false')

    fireEvent.click(screen.getByText('reports.refresh'))
    await waitFor(() => expect(getStats.mock.calls.length).toBeGreaterThan(1))

    // The reload offers 'day' again, but the user's choice survives it.
    expect(screen.getByRole('button', { name: 'avg' })).toHaveAttribute('aria-pressed', 'false')
  })

  it('compares against the preceding window of the same width, overlaid', async () => {
    // "Previous period" lives in the compare-device select, so the two kinds of
    // comparison cannot both be active. Selecting it fetches the same device
    // over the window immediately before, shifted forward to overlay.
    localStorage.setItem(KEY, JSON.stringify({ ain: 'a2', type: 'power', presetKey: 'last48h' }))
    getStats.mockImplementation((_ain: string, _type: string, from: string) =>
      Promise.resolve({ data: [{ time: from, value: 5, type: 'power' }] }))

    render(<ReportsPage />)
    await waitFor(() => expect(getStats).toHaveBeenCalled())
    await screen.findByText(/reports\.chartTitle/)

    const [, , currentFrom, currentTo] = getStats.mock.calls[0]
    getStats.mockClear()

    fireEvent.change(screen.getByLabelText('reports.compareDevice'), { target: { value: '__previous__' } })
    await waitFor(() => expect(getStats.mock.calls.length).toBeGreaterThanOrEqual(2))

    const spanMs = new Date(currentTo as string).getTime() - new Date(currentFrom as string).getTime()
    const secondCall = getStats.mock.calls.find(
      (c) => new Date(c[2] as string).getTime() < new Date(currentFrom as string).getTime(),
    )
    expect(secondCall, 'a request for the preceding window').toBeTruthy()
    // Same device, same width, immediately before.
    expect(secondCall![0]).toBe('a2')
    expect(new Date(secondCall![2] as string).getTime())
      .toBe(new Date(currentFrom as string).getTime() - spanMs)
    expect(new Date(secondCall![3] as string).getTime())
      .toBe(new Date(currentTo as string).getTime() - spanMs)
  })

  it('keeps the previous-period selection across a reload', async () => {
    // The sentinel is not a device, so the restore path has to allow it
    // explicitly or the setting silently reverts to "none".
    localStorage.setItem(KEY, JSON.stringify({
      ain: 'a2', ain2: '__previous__', type: 'power', presetKey: 'last48h',
    }))
    getStats.mockImplementation((_ain: string, _type: string, from: string) =>
      Promise.resolve({ data: [{ time: from, value: 5, type: 'power' }] }))

    render(<ReportsPage />)
    await waitFor(() => expect(getStats.mock.calls.length).toBeGreaterThanOrEqual(2))

    expect((screen.getByLabelText('reports.compareDevice') as HTMLSelectElement).value).toBe('__previous__')
  })

  it('exports exactly the window that is on screen', async () => {
    // Not a range re-resolved at click time: what you download must be what the
    // chart is showing.
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const t0 = new Date(2026, 7, 9, 14, 0, 0)
    vi.setSystemTime(t0)
    exportStats.mockReset().mockResolvedValue(new Blob(['time,type,value,unit\n']))
    URL.createObjectURL = vi.fn(() => 'blob:stub')
    URL.revokeObjectURL = vi.fn()
    HTMLAnchorElement.prototype.click = vi.fn()
    localStorage.setItem(KEY, JSON.stringify({ ain: 'a2', type: 'power', presetKey: 'last48h' }))
    getStats.mockResolvedValue({ ain: 'a2', type: 'power', data: [{ time: t0.toISOString(), value: 1, type: 'power' }] })

    render(<ReportsPage />)
    await waitFor(() => expect(getStats).toHaveBeenCalled())
    await screen.findByText(/reports\.chartTitle/)
    const loadedFrom = getStats.mock.calls[0][2]
    const loadedTo = getStats.mock.calls[0][3]

    fireEvent.click(screen.getByText('reports.export ▾'))
    fireEvent.click(screen.getByText('reports.exportCsv'))

    await waitFor(() => expect(exportStats).toHaveBeenCalled())
    const [ain, type, from, to, format] = exportStats.mock.calls[0]
    expect(ain).toBe('a2')
    expect(type).toBe('power')
    expect(from).toBe(loadedFrom)
    expect(to).toBe(loadedTo)
    expect(format).toBe('csv')
    vi.useRealTimers()
  })

  it('keeps an average switched off across every reload that leaves the range alone', async () => {
    getStats.mockResolvedValue({
      ain: 'a1',
      type: 'temperature',
      data: [{ time: '2026-08-09T12:00:00Z', value: 1, type: 'temperature' }, { time: '2026-08-09T13:00:00Z', value: 2, type: 'temperature' }],
    })

    render(<ReportsPage />)

    const chip = await screen.findByRole('button', { name: 'avg' })
    fireEvent.click(chip)
    expect(chip).toHaveAttribute('aria-pressed', 'false')

    // Metric, device, compare device and the events overlay all reload the same
    // window, so none of them may resurrect the average.
    fireEvent.change(screen.getByLabelText('reports.metric'), { target: { value: 'power' } })
    await waitFor(() => expect(getStats.mock.calls.some((c) => c[1] === 'power')).toBe(true))
    expect(screen.getByRole('button', { name: 'avg' })).toHaveAttribute('aria-pressed', 'false')

    fireEvent.change(screen.getByLabelText('reports.device'), { target: { value: 'a2' } })
    await waitFor(() => expect(getStats.mock.calls.some((c) => c[0] === 'a2')).toBe(true))
    expect(screen.getByRole('button', { name: 'avg' })).toHaveAttribute('aria-pressed', 'false')

    fireEvent.click(screen.getByRole('button', { name: 'reports.showEvents' }))
    await waitFor(() => expect(getReportAlertEvents).toHaveBeenCalled())
    expect(screen.getByRole('button', { name: 'avg' })).toHaveAttribute('aria-pressed', 'false')
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
