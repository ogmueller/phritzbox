import { useState, useRef, useEffect, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { getStats, refreshStats, getReportAlertEvents, exportStats, StatPoint, ReportAlertEvent } from '../api/stats'
import { useDeviceContext } from '../contexts/DeviceContext'
import { PageHeader } from '../components/layout/PageHeader'
import { Card } from '../components/ui/Card'
import { Button } from '../components/ui/Button'
import { SelectField } from '../components/ui/SelectField'
import { DateField } from '../components/ui/DateField'
import { Popover } from '../components/ui/Popover'
import { ToggleChip } from '../components/ui/ToggleChip'
import { TimeSeriesChart, Period, ChartEvent, getAvgStyle, selectAveragePeriods } from '../components/charts/TimeSeriesChart'
import { HOUR_MS, PRESETS, DEFAULT_PRESET_KEY, localDate, normalisePresetKey, presetDates, resolveRange } from './timeRange'
import { METRICS, DEFAULT_METRIC, metric as metricMeta } from '../metrics'
import { pushNotification } from '../notifications/bus'

const SECOND_COLOR = '#0E9AA7' // distinct from the metric colours and the avg lines

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
  showMinMax: boolean
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

/**
 * Where the time range starts on mount. A saved preset re-resolves against
 * *now*, so coming back a day later still shows a full window; a saved custom
 * range is kept verbatim because the user picked those dates on purpose.
 */
function initialRange(saved: SavedFilter | null): { presetKey: string | null; from: string; to: string } {
  const presetKey = saved ? normalisePresetKey(saved.presetKey) : DEFAULT_PRESET_KEY
  const preset = PRESETS.find((p) => p.key === presetKey)
  if (preset) {
    return { presetKey, ...presetDates(preset.hours) }
  }
  const fallback = presetDates(24 * 7)
  return { presetKey, from: saved?.from ?? fallback.from, to: saved?.to ?? fallback.to }
}

export function ReportsPage() {
  const { t, i18n } = useTranslation()
  const { devices } = useDeviceContext()
  // Read the persisted filter once, then resolve the range it implies in a
  // single step — two separate `new Date()` calls could straddle midnight.
  const savedRef = useRef(loadSavedFilter())
  const saved = savedRef.current
  const [initial] = useState(() => initialRange(saved))

  const [selectedAin, setSelectedAin]       = useState('')
  const [selectedAin2, setSelectedAin2]     = useState('')
  const [selectedType, setSelectedType]     = useState(() => saved?.type ?? DEFAULT_METRIC)
  const [presetKey, setPresetKey]           = useState<string | null>(initial.presetKey)
  const [from, setFrom]                     = useState(initial.from)
  const [to, setTo]                         = useState(initial.to)
  const [data, setData]                     = useState<StatPoint[]>([])
  const [data2, setData2]                   = useState<StatPoint[]>([])
  // The window the shown data was actually fetched for. Kept in state rather
  // than re-resolved during render: a rolling preset resolves against *now*, so
  // rendering it would drift away from the data on every unrelated re-render.
  const [loadedRange, setLoadedRange]       = useState<{ from: string; to: string } | null>(null)
  const [rawEvents, setRawEvents]           = useState<ReportAlertEvent[]>([])
  const [loading, setLoading]               = useState(false)
  const [error, setError]                   = useState<string | null>(null)
  const [availablePeriods, setAvailablePeriods] = useState<Period[]>([])
  const [enabledPeriods, setEnabledPeriods]     = useState<Period[]>([])
  const [fitToData, setFitToData]               = useState(() => saved?.fitToData ?? true)
  const [showEvents, setShowEvents]             = useState(() => saved?.showEvents ?? false)
  const [showMinMax, setShowMinMax]             = useState(() => saved?.showMinMax ?? false)
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
    doLoad(ain, selectedType, resolveRange(presetKey, from, to), { keepAvg: saved?.enabledPeriods, ain2, showEvents: saved?.showEvents ?? showEvents })
  }, [devices])

  // Persist the current filter on any change.
  useEffect(() => {
    try {
      const payload: SavedFilter = { ain: selectedAin, ain2: selectedAin2, type: selectedType, presetKey, from, to, fitToData, showEvents, showMinMax, enabledPeriods }
      localStorage.setItem(REPORTS_FILTER_KEY, JSON.stringify(payload))
    } catch {
      // ignore quota / private-mode write failures
    }
  }, [selectedAin, selectedAin2, selectedType, presetKey, from, to, fitToData, showEvents, showMinMax, enabledPeriods])

  const doLoad = async (
    ain: string,
    type: string,
    range: { from: string; to: string },
    opts?: { keepAvg?: Period[]; ain2?: string; showEvents?: boolean },
  ) => {
    if (!ain) return
    const { from: fromDate, to: toDate } = range
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
      setLoadedRange(range)
      setLoaded(true)

      const diffDays = (new Date(toDate).getTime() - new Date(fromDate).getTime()) / (24 * HOUR_MS)
      const periods = primary.data.length >= 2 ? selectAveragePeriods(diffDays) : []
      setAvailablePeriods(periods)
      // Every caller that leaves the time range alone hands over the selection
      // to preserve, intersected with what this range offers — an average the
      // user switched off must not come back on the next load. Only a range
      // change passes nothing, enabling everything the new range has (its
      // periods can be entirely different ones).
      setEnabledPeriods(opts?.keepAvg ? periods.filter((p) => opts.keepAvg!.includes(p)) : periods)

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
    doLoad(ain, selectedType, resolveRange(presetKey, from, to), { keepAvg: enabledPeriods })
  }

  const handleMetricChange = (type: string) => {
    setSelectedType(type)
    doLoad(selectedAin, type, resolveRange(presetKey, from, to), { keepAvg: enabledPeriods })
  }

  const handleSecondDeviceChange = (ain2: string) => {
    setSelectedAin2(ain2)
    if (loaded) doLoad(selectedAin, selectedType, resolveRange(presetKey, from, to), { ain2, keepAvg: enabledPeriods })
  }

  const handleShowEventsChange = (checked: boolean) => {
    setShowEvents(checked)
    if (loaded) doLoad(selectedAin, selectedType, resolveRange(presetKey, from, to), { showEvents: checked, keepAvg: enabledPeriods })
  }

  const handlePreset = (preset: { key: string; hours: number }) => {
    setPresetKey(preset.key)
    // Mirror the window onto the custom pickers so switching to Custom starts
    // from what is on screen rather than a stale range.
    const dates = presetDates(preset.hours)
    setFrom(dates.from)
    setTo(dates.to)
    if (loaded) doLoad(selectedAin, selectedType, resolveRange(preset.key, dates.from, dates.to))
  }

  const handleCustomDate = (nextFrom: string, nextTo: string) => {
    setFrom(nextFrom)
    setTo(nextTo)
    setPresetKey(null)
    if (nextFrom <= nextTo) doLoad(selectedAin, selectedType, resolveRange(null, nextFrom, nextTo))
  }

  const handleRefresh = async () => {
    setRefreshing(true)
    setError(null)
    try {
      await refreshStats()
      if (!loaded) return
      // A preset re-resolves against *now*, so a page left open for hours pulls
      // the window its label promises rather than the one captured on load.
      // A custom range is left untouched — the user picked those dates on purpose.
      const preset = PRESETS.find((p) => p.key === presetKey)
      if (preset) {
        const dates = presetDates(preset.hours)
        setFrom(dates.from)
        setTo(dates.to)
      }
      await doLoad(selectedAin, selectedType, resolveRange(presetKey, from, to), { keepAvg: enabledPeriods })
    } catch (e) {
      setError(e instanceof Error ? e.message : t('reports.refreshFailed'))
    } finally {
      setRefreshing(false)
    }
  }

  const downloadExport = async (format: 'csv' | 'json') => {
    if (!loadedRange) return
    try {
      // Exactly the window on screen, not one re-resolved at click time.
      const blob = await exportStats(
        selectedAin,
        selectedType,
        loadedRange.from,
        loadedRange.to,
        format,
        // German Excel reads ';' as the column separator and ',' as the decimal mark.
        i18n.language.startsWith('de') ? ';' : undefined,
      )
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `phritzbox-${selectedAin.replace(/\s+/g, '')}-${selectedType}.${format}`
      a.click()
      URL.revokeObjectURL(url)
    } catch (e) {
      pushNotification({ severity: 'error', message: e instanceof Error ? e.message : t('reports.exportFailed') })
    }
  }

  const togglePeriod = (key: string, checked: boolean) =>
    setEnabledPeriods((prev) => checked ? [...prev, key as Period] : prev.filter((x) => x !== key))

  const meta = metricMeta(selectedType)

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

  const fmtShort = (d: string) => localDate(d).toLocaleDateString(i18n.language, { day: 'numeric', month: 'short' })
  // ISO 'YYYY-MM-DD' → 'DD.MM.YYYY' (matches the native date inputs' display).
  const fmtDate = (d: string) => d.split('-').reverse().join('.')
  // An instant → "9 Aug, 16:00". h23 so it reads like the chart's own time axis
  // (and never renders midnight as "24:00", which hour12:false can).
  const fmtInstant = (iso: string) => new Date(iso).toLocaleString(i18n.language, {
    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  })
  const loadedWindow = loadedRange && `${fmtInstant(loadedRange.from)} – ${fmtInstant(loadedRange.to)}`
  const activePreset = PRESETS.find((p) => p.key === presetKey)
  // The toolbar trigger names the preset ("Last 24 hours"); a custom range has
  // no name and spells out its dates.
  const rangeLabel = activePreset ? t(activePreset.labelKey) : `${fmtShort(from)} – ${fmtShort(to)}`
  // The chart title states the window itself, never the preset's name: the name
  // reads the same before and after a pull slides the window forward, and a span
  // of 3 days or less puts no date on the time axis either.
  const rangeLabelLong = activePreset
    ? (loadedWindow ?? t(activePreset.labelKey))
    : `${fmtDate(from)} – ${fmtDate(to)}`

  const titleDevice = deviceName(selectedAin) + (selectedAin2 ? ` + ${deviceName(selectedAin2)}` : '')

  return (
    <div className="page">
      <PageHeader
        title={t('reports.title')}
        subtitle={t('reports.subtitle')}
        actions={
          <>
            {loaded && data.length > 0 && (
              <Popover triggerClassName="btn btn--secondary btn--sm" align="right" label={`${t('reports.export')} ▾`}>
                {(close) => (
                  <div className="daterange-panel">
                    <button type="button" className="daterange-preset" onClick={() => { downloadExport('csv'); close() }}>
                      {t('reports.exportCsv')}
                    </button>
                    <button type="button" className="daterange-preset" onClick={() => { downloadExport('json'); close() }}>
                      {t('reports.exportJson')}
                    </button>
                  </div>
                )}
              </Popover>
            )}
            <Button variant="primary" size="sm" onClick={handleRefresh} loading={refreshing}>
              {refreshing ? t('reports.refreshing') : t('reports.refresh')}
            </Button>
          </>
        }
      />

      <Card>
        <div className={`report-toolbar${refreshing ? ' is-busy' : ''}`}>
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
            options={METRICS.map((s) => ({ value: s.value, label: t(s.labelKey) }))}
          />

          <div className="toolbar-field">
            <span className="form-label">{t('reports.timeRange')}</span>
            <Popover triggerClassName="daterange-trigger" align="left" label={`${rangeLabel} ▾`}>
              {(close) => (
                <div className="daterange-panel">
                  <span className="daterange-heading">{t('reports.quickRanges')}</span>
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
                      onChange={(v) => handleCustomDate(v, to)}
                    />
                    <DateField
                      label={t('reports.to')}
                      id="report-to"
                      value={to}
                      min={from}
                      onChange={(v) => handleCustomDate(from, v)}
                    />
                  </div>
                  {from > to && <div className="filter-bar-error">{t('reports.invalidRange')}</div>}
                </div>
              )}
            </Popover>
          </div>

        </div>

        <div className={`report-chips${refreshing ? ' is-busy' : ''}`}>
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
          <ToggleChip active={showMinMax} onClick={() => setShowMinMax(!showMinMax)}>{t('reports.showMinMax')}</ToggleChip>
          <ToggleChip active={showEvents} onClick={() => handleShowEventsChange(!showEvents)}>{t('reports.showEvents')}</ToggleChip>
        </div>
      </Card>

      {error && <div className="alert alert--danger">{error}</div>}

      {data.length > 0 && (
        <Card title={t('reports.chartTitle', {
          metric: t(meta.labelKey),
          device: titleDevice,
          range: rangeLabelLong,
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
              showMinMax={showMinMax}
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
