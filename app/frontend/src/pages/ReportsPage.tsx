import { useState, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { getStats, StatPoint } from '../api/stats'
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

export function ReportsPage() {
  const { t, i18n } = useTranslation()
  const { devices } = useDeviceContext()
  const [selectedAin, setSelectedAin]       = useState('')
  const [selectedType, setSelectedType]     = useState('temperature')
  const [from, setFrom]                     = useState(() => { const d = new Date(); d.setDate(d.getDate() - 7); return isoDate(d) })
  const [to, setTo]                         = useState(() => isoDate(new Date()))
  const [data, setData]                     = useState<StatPoint[]>([])
  const [loading, setLoading]               = useState(false)
  const [error, setError]                   = useState<string | null>(null)
  const [availablePeriods, setAvailablePeriods] = useState<Period[]>([])
  const [enabledPeriods, setEnabledPeriods]     = useState<Period[]>([])
  const [fitToData, setFitToData]               = useState(true)
  const [loaded, setLoaded]                     = useState(false)

  // Use a ref to track the latest request so we can ignore stale responses
  const requestIdRef = useRef(0)

  // Auto-select first device when devices load
  if (devices.length > 0 && !selectedAin) {
    setSelectedAin(devices[0].ain)
  }

  const doLoad = async (ain: string, type: string, fromDate: string, toDate: string) => {
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
      setEnabledPeriods(periods)
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

  const togglePeriod = (key: string, checked: boolean) =>
    setEnabledPeriods((prev) => checked ? [...prev, key as Period] : prev.filter((x) => x !== key))

  const meta = STAT_TYPES.find((s) => s.value === selectedType)!

  return (
    <div className="page">
      <PageHeader title={t('reports.title')} subtitle={t('reports.subtitle')} />

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
            onChange={setFrom}
          />

          <DateField
            label={t('reports.to')}
            id="report-to"
            value={to}
            onChange={setTo}
          />

          <div className="form-group form-group--btn">
            <Button onClick={handleLoad} disabled={loading || !selectedAin}>
              {loading ? t('common.loading') : t('reports.load')}
            </Button>
          </div>
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
