import { useState, useRef, useEffect, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { getStats, refreshStats, getReportAlertEvents, StatPoint, ReportAlertEvent } from '../api/stats'
import { useDeviceContext } from '../contexts/DeviceContext'
import { PageHeader } from '../components/layout/PageHeader'
import { Card } from '../components/ui/Card'
import { Button } from '../components/ui/Button'
import { SelectField } from '../components/ui/SelectField'
import { DateField } from '../components/ui/DateField'
import { Popover } from '../components/ui/Popover'
import { ToggleChip } from '../components/ui/ToggleChip'
import { TimeSeriesChart, Period, ChartEvent, getAvgStyle, selectAveragePeriods } from '../components/charts/TimeSeriesChart'

const STAT_TYPES = [
  { value: 'temperature', labelKey: 'chart.temperature' as const, unit: '°C',  color: '#E8620D' },
  { value: 'power',       labelKey: 'chart.power'       as const, unit: 'W',   color: '#0046A8' },
  { value: 'energy',      labelKey: 'chart.energy'       as const, unit: 'Wh',  color: '#4E9A2E' },
  { value: 'voltage',     labelKey: 'chart.voltage'      as const, unit: 'V',   color: '#6B7280' },
]

const SECOND_COLOR = '#0E9AA7' // distinct from the metric colours and the avg lines

function isoDate(d: Date) {
  return d.toISOString().slice(0, 10)
}

// Date-range quick picks. `days` = how many days back the range starts from today.
const PRESETS = [
  { key: 'today',      labelKey: 'reports.today'      as const, days: 0 },
  { key: 'yesterday',  labelKey: 'reports.yesterday'  as const, days: 1 },
  { key: 'last7Days',  labelKey: 'reports.last7Days'  as const, days: 7 },
  { key: 'last30Days', labelKey: 'reports.last30Days' as const, days: 30 },
]

function presetRange(days: number): { from: string; to: string } {
  const start = new Date()
  start.setDate(start.getDate() - days)
  return { from: isoDate(start), to: isoDate(new Date()) }
}

// Data point nearest a timestamp. Markers snap to it so each dot sits exactly on
// the device's line vertex and shares an x with the line — which makes it appear
// in the axis tooltip alongside the line values.
function nearestPoint(series: StatPoint[], iso: string): StatPoint | null {
  if (series.length === 0) return null
  const t = new Date(iso).getTime()
  let best = series[0]
  let bestDiff = Math.abs(new Date(best.time).getTime() - t)
  for (const p of series) {
    const diff = Math.abs(new Date(p.time).getTime() - t)
    if (diff < bestDiff) { best = p; bestDiff = diff }
  }
  return best
}

// Persisted Reports filter so the page restores the last "search" on return.
const REPORTS_FILTER_KEY = 'phritzbox_reports_filter'

interface SavedFilter {
  ain: string
  ain2: string
  type: string
  presetKey: string | null
  from: string
  to: string
  fitToData: boolean
  showEvents: boolean
  enabledPeriods: Period[]
}

function loadSavedFilter(): SavedFilter | null {
  try {
    const raw = localStorage.getItem(REPORTS_FILTER_KEY)
    return raw ? (JSON.parse(raw) as SavedFilter) : null
  } catch {
    return null
  }
}

export function ReportsPage() {
  const { t, i18n } = useTranslation()
  const { devices } = useDeviceContext()
  // Read the persisted filter once. A saved preset re-resolves to dates relative
  // to *today*, so e.g. "Yesterday" stays correct when returning on a later day.
  const savedRef = useRef(loadSavedFilter())
  const saved = savedRef.current
  const savedPresetDays = saved?.presetKey ? PRESETS.find((p) => p.key === saved.presetKey)?.days : undefined

  const [selectedAin, setSelectedAin]       = useState('')
  const [selectedAin2, setSelectedAin2]     = useState('')
  const [selectedType, setSelectedType]     = useState(() => saved?.type ?? 'temperature')
  const [presetKey, setPresetKey]           = useState<string | null>(() => saved?.presetKey ?? null)
  const [from, setFrom]                     = useState(() => savedPresetDays !== undefined ? presetRange(savedPresetDays).from : (saved?.from ?? presetRange(7).from))
  const [to, setTo]                         = useState(() => savedPresetDays !== undefined ? presetRange(savedPresetDays).to   : (saved?.to   ?? presetRange(7).to))
  const [data, setData]                     = useState<StatPoint[]>([])
  const [data2, setData2]                   = useState<StatPoint[]>([])
  const [rawEvents, setRawEvents]           = useState<ReportAlertEvent[]>([])
  const [loading, setLoading]               = useState(false)
  const [error, setError]                   = useState<string | null>(null)
  const [availablePeriods, setAvailablePeriods] = useState<Period[]>([])
  const [enabledPeriods, setEnabledPeriods]     = useState<Period[]>([])
  const [fitToData, setFitToData]               = useState(() => saved?.fitToData ?? true)
  const [showEvents, setShowEvents]             = useState(() => saved?.showEvents ?? false)
  const [loaded, setLoaded]                     = useState(false)
  const [refreshing, setRefreshing]             = useState(false)

  // Use a ref to track the latest request so we can ignore stale responses
  const requestIdRef = useRef(0)

  const deviceName = (ain: string) => devices.find((d) => d.ain === ain)?.name ?? ain

  // Once devices are available, pick the device and (if a filter was saved)
  // auto-run the last search. Runs only once.
  const didRestore = useRef(false)
  useEffect(() => {
    if (didRestore.current || devices.length === 0) return
    didRestore.current = true
    const ain = saved?.ain && devices.some((d) => d.ain === saved.ain) ? saved.ain : devices[0].ain
    const ain2 = saved?.ain2 && devices.some((d) => d.ain === saved.ain2) ? saved.ain2 : ''
    setSelectedAin(ain)
    setSelectedAin2(ain2)
    // No Load button: always run the initial query (saved filter, or defaults).
    doLoad(ain, selectedType, from, to, { restoreAvg: saved?.enabledPeriods, ain2, showEvents: saved?.showEvents ?? showEvents })
  }, [devices])

  // Persist the current filter on any change.
  useEffect(() => {
    try {
      const payload: SavedFilter = { ain: selectedAin, ain2: selectedAin2, type: selectedType, presetKey, from, to, fitToData, showEvents, enabledPeriods }
      localStorage.setItem(REPORTS_FILTER_KEY, JSON.stringify(payload))
    } catch {
      // ignore quota / private-mode write failures
    }
  }, [selectedAin, selectedAin2, selectedType, presetKey, from, to, fitToData, showEvents, enabledPeriods])

  const doLoad = async (
    ain: string,
    type: string,
    fromDate: string,
    toDate: string,
    opts?: { restoreAvg?: Period[]; ain2?: string; showEvents?: boolean },
  ) => {
    if (!ain) return
    const ain2 = opts?.ain2 ?? selectedAin2
    const wantEvents = opts?.showEvents ?? showEvents
    const thisRequest = ++requestIdRef.current
    setLoading(true)
    setError(null)
    try {
      const [primary, secondary] = await Promise.all([
        getStats(ain, type, fromDate, toDate),
        ain2 ? getStats(ain2, type, fromDate, toDate) : Promise.resolve({ data: [] as StatPoint[] }),
      ])
      if (thisRequest !== requestIdRef.current) return
      setData(primary.data)
      setData2(secondary.data)
      setLoaded(true)

      const diffDays = (new Date(toDate).getTime() - new Date(fromDate).getTime()) / (1000 * 60 * 60 * 24)
      const periods = primary.data.length >= 2 ? selectAveragePeriods(diffDays) : []
      setAvailablePeriods(periods)
      // On restore, honour the saved averages (intersected with what this range
      // offers); a normal load enables all available periods.
      setEnabledPeriods(opts?.restoreAvg ? periods.filter((p) => opts.restoreAvg!.includes(p)) : periods)

      // Alert events are best-effort: a failure must not blank the chart.
      if (wantEvents) {
        try {
          const ev = await getReportAlertEvents(type, fromDate, toDate, [ain, ain2].filter(Boolean))
          if (thisRequest === requestIdRef.current) setRawEvents(ev)
        } catch {
          if (thisRequest === requestIdRef.current) setRawEvents([])
        }
      } else {
        setRawEvents([])
      }
    } catch (e) {
      if (thisRequest !== requestIdRef.current) return
      setError(e instanceof Error ? e.message : t('reports.failedToLoad'))
      setData([]); setData2([]); setRawEvents([])
      setAvailablePeriods([])
      setEnabledPeriods([])
    } finally {
      if (thisRequest === requestIdRef.current) {
        setLoading(false)
      }
    }
  }

  const handleDeviceChange = (ain: string) => {
    setSelectedAin(ain)
    doLoad(ain, selectedType, from, to)
  }

  const handleMetricChange = (type: string) => {
    setSelectedType(type)
    doLoad(selectedAin, type, from, to)
  }

  const handleSecondDeviceChange = (ain2: string) => {
    setSelectedAin2(ain2)
    if (loaded) doLoad(selectedAin, selectedType, from, to, { ain2 })
  }

  const handleShowEventsChange = (checked: boolean) => {
    setShowEvents(checked)
    if (loaded) doLoad(selectedAin, selectedType, from, to, { showEvents: checked })
  }

  const applyPreset = (fromDate: string, toDate: string) => {
    setFrom(fromDate)
    setTo(toDate)
    if (loaded) doLoad(selectedAin, selectedType, fromDate, toDate)
  }

  const handlePreset = (preset: { key: string; days: number }) => {
    setPresetKey(preset.key)
    const { from: f, to: t2 } = presetRange(preset.days)
    applyPreset(f, t2)
  }

  const handleRefresh = async () => {
    setRefreshing(true)
    setError(null)
    try {
      await refreshStats()
      if (loaded) await doLoad(selectedAin, selectedType, from, to)
    } catch (e) {
      setError(e instanceof Error ? e.message : t('reports.refreshFailed'))
    } finally {
      setRefreshing(false)
    }
  }

  const togglePeriod = (key: string, checked: boolean) =>
    setEnabledPeriods((prev) => checked ? [...prev, key as Period] : prev.filter((x) => x !== key))

  const meta = STAT_TYPES.find((s) => s.value === selectedType)!

  // Map each alert event onto the line(s) of the device(s) it involves.
  const chartEvents: ChartEvent[] = useMemo(() => {
    if (!showEvents || rawEvents.length === 0) return []
    const lines = [
      { ain: selectedAin, series: data },
      ...(selectedAin2 ? [{ ain: selectedAin2, series: data2 }] : []),
    ]
    const stateKey = { triggered: 'alerts.stateTriggered', resolved: 'alerts.stateResolved', rearmed: 'alerts.stateRearmed' } as const
    const fmtVal = (v: number) => `${Number(v.toFixed(2))} ${meta.unit}`
    const out: ChartEvent[] = []
    for (const e of rawEvents) {
      // Notification-style summary (matches the Recent activity reading format).
      let summary = `${e.ruleName} — ${t(stateKey[e.state])}`
      if (e.valueDisplay !== null) {
        summary += `: ${fmtVal(e.valueDisplay)}`
        if (e.compareDisplay !== null) summary += ` ↔ ${fmtVal(e.compareDisplay)}`
      }
      for (const line of lines) {
        if (e.sid !== line.ain && e.compareSid !== line.ain) continue
        const p = nearestPoint(line.series, e.createdAt)
        if (!p) continue
        out.push({ time: p.time, value: p.value, state: e.state, summary })
      }
    }
    return out
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showEvents, rawEvents, data, data2, selectedAin, selectedAin2, meta.unit])

  const secondOptions = [
    { value: '', label: t('reports.compareNone') },
    ...devices.filter((d) => d.ain !== selectedAin).map((d) => ({ value: d.ain, label: d.name })),
  ]

  const fmtShort = (d: string) => new Date(d).toLocaleDateString(i18n.language, { day: 'numeric', month: 'short' })
  const activePreset = PRESETS.find((p) => p.key === presetKey)
  const dateRangeLabel = activePreset ? t(activePreset.labelKey) : `${fmtShort(from)} – ${fmtShort(to)}`

  const titleDevice = deviceName(selectedAin) + (selectedAin2 ? ` + ${deviceName(selectedAin2)}` : '')

  return (
    <div className="page">
      <PageHeader
        title={t('reports.title')}
        subtitle={t('reports.subtitle')}
        actions={
          <Button variant="primary" size="sm" onClick={handleRefresh} loading={refreshing}>
            {refreshing ? t('reports.refreshing') : t('reports.refresh')}
          </Button>
        }
      />

      <Card>
        <div className="report-toolbar">
          <SelectField
            className="toolbar-field"
            label={t('reports.device')}
            id="report-device"
            value={selectedAin}
            onChange={handleDeviceChange}
            options={devices.map((d) => ({ value: d.ain, label: d.name }))}
          />

          <SelectField
            className="toolbar-field"
            label={t('reports.compareDevice')}
            id="report-device-2"
            value={selectedAin2}
            onChange={handleSecondDeviceChange}
            options={secondOptions}
          />

          <SelectField
            className="toolbar-field"
            label={t('reports.metric')}
            id="report-metric"
            value={selectedType}
            onChange={handleMetricChange}
            options={STAT_TYPES.map((s) => ({ value: s.value, label: t(s.labelKey) }))}
          />

          <div className="toolbar-field">
            <span className="form-label">{t('reports.dateRange')}</span>
            <Popover triggerClassName="daterange-trigger" align="left" label={`${dateRangeLabel} ▾`}>
              {(close) => (
                <div className="daterange-panel">
                  <span className="daterange-heading">{t('reports.presets')}</span>
                  <div className="daterange-presets">
                    {PRESETS.map((p) => (
                      <button
                        key={p.key}
                        type="button"
                        className={`daterange-preset${presetKey === p.key ? ' daterange-preset--active' : ''}`}
                        onClick={() => { handlePreset(p); close() }}
                      >
                        {t(p.labelKey)}
                      </button>
                    ))}
                  </div>
                  <span className="daterange-heading">{t('reports.presetCustom')}</span>
                  <div className="daterange-custom">
                    <DateField
                      label={t('reports.from')}
                      id="report-from"
                      value={from}
                      max={to}
                      onChange={(v) => { setFrom(v); setPresetKey(null); if (v <= to) doLoad(selectedAin, selectedType, v, to) }}
                    />
                    <DateField
                      label={t('reports.to')}
                      id="report-to"
                      value={to}
                      min={from}
                      onChange={(v) => { setTo(v); setPresetKey(null); if (from <= v) doLoad(selectedAin, selectedType, from, v) }}
                    />
                  </div>
                  {from > to && <div className="filter-bar-error">{t('reports.invalidRange')}</div>}
                </div>
              )}
            </Popover>
          </div>

          {refreshing && (
            <div className="filter-bar-status">{t('reports.refreshing')}</div>
          )}
        </div>

        <div className="report-chips">
          <span className="report-chips-label">{t('reports.display')}</span>
          {availablePeriods.map((p) => {
            const style = getAvgStyle(p)
            return (
              <ToggleChip key={p} active={enabledPeriods.includes(p)} color={style.color} onClick={() => togglePeriod(p, !enabledPeriods.includes(p))}>
                {style.name}
              </ToggleChip>
            )
          })}
          <ToggleChip active={fitToData} onClick={() => setFitToData(!fitToData)}>{t('reports.fitToData')}</ToggleChip>
          <ToggleChip active={showEvents} onClick={() => handleShowEventsChange(!showEvents)}>{t('reports.showEvents')}</ToggleChip>
        </div>
      </Card>

      {error && <div className="alert alert--danger">{error}</div>}

      {data.length > 0 && (
        <Card title={t('reports.chartTitle', {
          metric: t(meta.labelKey),
          device: titleDevice,
          from: new Date(from).toLocaleDateString(i18n.language, { day: 'numeric', month: 'numeric', year: 'numeric' }),
          to: new Date(to).toLocaleDateString(i18n.language, { day: 'numeric', month: 'numeric', year: 'numeric' }),
        })}>
          <div className="chart-container">
            {loading && (
              <div className="chart-loading-overlay">
                <span className="chart-loading-spinner" />
              </div>
            )}
            <TimeSeriesChart
              data={data}
              label={deviceName(selectedAin)}
              unit={meta.unit}
              color={meta.color}
              height={340}
              enabledAvgPeriods={enabledPeriods}
              fitToData={fitToData}
              data2={selectedAin2 ? data2 : undefined}
              label2={selectedAin2 ? deviceName(selectedAin2) : undefined}
              color2={SECOND_COLOR}
              events={chartEvents}
              eventsLabel={t('reports.eventsLegend')}
            />
          </div>
        </Card>
      )}

      {loaded && !loading && data.length === 0 && !error && (
        <div className="empty-state">{t('reports.emptyState')}</div>
      )}
    </div>
  )
}
