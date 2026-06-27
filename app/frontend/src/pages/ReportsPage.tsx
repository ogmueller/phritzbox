import { useState, useRef, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { getStats, refreshStats, StatPoint } from '../api/stats'
import { useDeviceContext } from '../contexts/DeviceContext'
import { PageHeader } from '../components/layout/PageHeader'
import { Card } from '../components/ui/Card'
import { Button } from '../components/ui/Button'
import { SelectField } from '../components/ui/SelectField'
import { DateField } from '../components/ui/DateField'
import { CheckboxGroup } from '../components/ui/CheckboxGroup'
import { TimeSeriesChart, Period, getAvgStyle, selectAveragePeriods } from '../components/charts/TimeSeriesChart'

const STAT_TYPES = [
  { value: 'temperature', labelKey: 'chart.temperature' as const, unit: '°C',  color: '#E8620D' },
  { value: 'power',       labelKey: 'chart.power'       as const, unit: 'W',   color: '#0046A8' },
  { value: 'energy',      labelKey: 'chart.energy'       as const, unit: 'Wh',  color: '#4E9A2E' },
  { value: 'voltage',     labelKey: 'chart.voltage'      as const, unit: 'V',   color: '#6B7280' },
]

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

// Persisted Reports filter so the page restores the last "search" on return.
const REPORTS_FILTER_KEY = 'phritzbox_reports_filter'

interface SavedFilter {
  ain: string
  type: string
  presetKey: string | null
  from: string
  to: string
  fitToData: boolean
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
  const [selectedType, setSelectedType]     = useState(() => saved?.type ?? 'temperature')
  const [presetKey, setPresetKey]           = useState<string | null>(() => saved?.presetKey ?? null)
  const [from, setFrom]                     = useState(() => savedPresetDays !== undefined ? presetRange(savedPresetDays).from : (saved?.from ?? presetRange(7).from))
  const [to, setTo]                         = useState(() => savedPresetDays !== undefined ? presetRange(savedPresetDays).to   : (saved?.to   ?? presetRange(7).to))
  const [data, setData]                     = useState<StatPoint[]>([])
  const [loading, setLoading]               = useState(false)
  const [error, setError]                   = useState<string | null>(null)
  const [availablePeriods, setAvailablePeriods] = useState<Period[]>([])
  const [enabledPeriods, setEnabledPeriods]     = useState<Period[]>([])
  const [fitToData, setFitToData]               = useState(() => saved?.fitToData ?? true)
  const [loaded, setLoaded]                     = useState(false)
  const [refreshing, setRefreshing]             = useState(false)

  // Use a ref to track the latest request so we can ignore stale responses
  const requestIdRef = useRef(0)

  // Once devices are available, pick the device and (if a filter was saved)
  // auto-run the last search. Runs only once.
  const didRestore = useRef(false)
  useEffect(() => {
    if (didRestore.current || devices.length === 0) return
    didRestore.current = true
    const ain = saved?.ain && devices.some((d) => d.ain === saved.ain) ? saved.ain : devices[0].ain
    setSelectedAin(ain)
    if (saved) {
      doLoad(ain, selectedType, from, to, saved.enabledPeriods)
    }
  }, [devices])

  // Persist the current filter on any change.
  useEffect(() => {
    try {
      const payload: SavedFilter = { ain: selectedAin, type: selectedType, presetKey, from, to, fitToData, enabledPeriods }
      localStorage.setItem(REPORTS_FILTER_KEY, JSON.stringify(payload))
    } catch {
      // ignore quota / private-mode write failures
    }
  }, [selectedAin, selectedType, presetKey, from, to, fitToData, enabledPeriods])

  const doLoad = async (ain: string, type: string, fromDate: string, toDate: string, restoreAvg?: Period[]) => {
    if (!ain) return
    const thisRequest = ++requestIdRef.current
    setLoading(true)
    setError(null)
    try {
      const res = await getStats(ain, type, fromDate, toDate)
      // Ignore stale responses
      if (thisRequest !== requestIdRef.current) return
      setData(res.data)
      setLoaded(true)

      const diffDays = (new Date(toDate).getTime() - new Date(fromDate).getTime()) / (1000 * 60 * 60 * 24)
      const periods = res.data.length >= 2 ? selectAveragePeriods(diffDays) : []
      setAvailablePeriods(periods)
      // On restore, honour the saved averages (intersected with what this range
      // offers); a normal load enables all available periods.
      setEnabledPeriods(restoreAvg ? periods.filter((p) => restoreAvg.includes(p)) : periods)
    } catch (e) {
      if (thisRequest !== requestIdRef.current) return
      setError(e instanceof Error ? e.message : t('reports.failedToLoad'))
      setData([])
      setAvailablePeriods([])
      setEnabledPeriods([])
    } finally {
      if (thisRequest === requestIdRef.current) {
        setLoading(false)
      }
    }
  }

  const handleLoad = () => {
    doLoad(selectedAin, selectedType, from, to)
  }

  const handleMetricChange = (type: string) => {
    setSelectedType(type)
    if (loaded) {
      doLoad(selectedAin, type, from, to)
    }
  }

  const applyPreset = (fromDate: string, toDate: string) => {
    setFrom(fromDate)
    setTo(toDate)
    if (loaded) {
      doLoad(selectedAin, selectedType, fromDate, toDate)
    }
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
      if (loaded) {
        await doLoad(selectedAin, selectedType, from, to)
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : t('reports.refreshFailed'))
    } finally {
      setRefreshing(false)
    }
  }

  const togglePeriod = (key: string, checked: boolean) =>
    setEnabledPeriods((prev) => checked ? [...prev, key as Period] : prev.filter((x) => x !== key))

  const meta = STAT_TYPES.find((s) => s.value === selectedType)!

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
        <div className="filter-bar">
          <SelectField
            label={t('reports.device')}
            id="report-device"
            value={selectedAin}
            onChange={setSelectedAin}
            options={devices.map((d) => ({ value: d.ain, label: d.name }))}
          />

          <DateField
            label={t('reports.from')}
            id="report-from"
            value={from}
            max={to}
            onChange={(v) => { setFrom(v); setPresetKey(null) }}
          />

          <DateField
            label={t('reports.to')}
            id="report-to"
            value={to}
            min={from}
            onChange={(v) => { setTo(v); setPresetKey(null) }}
          />

          <div className="date-presets">
            {PRESETS.map((p) => (
              <Button key={p.key} variant={presetKey === p.key ? 'secondary' : 'ghost'} size="sm" onClick={() => handlePreset(p)} disabled={refreshing}>
                {t(p.labelKey)}
              </Button>
            ))}
          </div>

          <div className="form-group form-group--btn">
            <Button onClick={handleLoad} disabled={loading || refreshing || !selectedAin || from > to}>
              {loading ? t('common.loading') : t('reports.load')}
            </Button>
          </div>
          {from > to && (
            <div className="filter-bar-error">{t('reports.invalidRange')}</div>
          )}
          {refreshing && (
            <div className="filter-bar-status">{t('reports.refreshing')}</div>
          )}
        </div>

        {loaded && (
          <div className="avg-toggles">
            <SelectField
              label={t('reports.metric')}
              id="report-metric"
              value={selectedType}
              onChange={handleMetricChange}
              options={STAT_TYPES.map((s) => ({ value: s.value, label: t(s.labelKey) }))}
              className="avg-toggles-metric"
            />

            {availablePeriods.length > 0 && (
              <>
                <span className="avg-toggles-separator" />
                <CheckboxGroup
                  label={t('reports.averages')}
                  items={availablePeriods.map((p) => {
                    const style = getAvgStyle(p)
                    return { key: p, label: style.name, checked: enabledPeriods.includes(p), color: style.color }
                  })}
                  onChange={togglePeriod}
                />
              </>
            )}
            <span className="avg-toggles-separator" />
            <label className="checkbox-group-item">
              <input
                type="checkbox"
                checked={fitToData}
                onChange={(e) => setFitToData(e.target.checked)}
              />
              <span>{t('reports.fitToData')}</span>
            </label>
          </div>
        )}
      </Card>

      {error && <div className="alert alert--danger">{error}</div>}

      {data.length > 0 && (
        <Card title={t('reports.chartTitle', {
          metric: t(meta.labelKey),
          device: devices.find((d) => d.ain === selectedAin)?.name ?? selectedAin,
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
              label={t(meta.labelKey)}
              unit={meta.unit}
              color={meta.color}
              height={340}
              enabledAvgPeriods={enabledPeriods}
              fitToData={fitToData}
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
