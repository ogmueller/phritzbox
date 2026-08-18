import { useParams, Link } from 'react-router-dom'
import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { getDevice, getDeviceXml, setDeviceProtection, Device } from '../api/devices'
import { getStats, StatPoint } from '../api/stats'
import { useAuth } from '../contexts/AuthContext'
import { PageHeader } from '../components/layout/PageHeader'
import { Button } from '../components/ui/Button'
import { Modal } from '../components/ui/Modal'
import { Switch } from '../components/ui/Switch'
import { Card } from '../components/ui/Card'
import { DashboardIcon } from '../components/ui/NavIcons'
import { Badge, PresentBadge, OnOffBadge } from '../components/ui/Badge'
import { OutletToggle } from '../components/device/OutletToggle'
import { SetpointControl } from '../components/device/SetpointControl'
import { DeviceIcon } from '../components/device/DeviceIcon'
import { ProductImage } from '../components/device/ProductImage'
import { TemperatureChart } from '../components/charts/TemperatureChart'
import { PowerChart } from '../components/charts/PowerChart'
import { EnergyChart } from '../components/charts/EnergyChart'
import { VoltageChart } from '../components/charts/VoltageChart'
import { getStandby, Standby } from '../api/energy'
import { rollingRange } from './timeRange'
import { formatEnergy, formatCurrency, formatWatts, formatNumber } from '../format'

export function DeviceDetailPage() {
  const { t, i18n } = useTranslation()
  const { isAdmin } = useAuth()
  const { ain } = useParams<{ ain: string }>()
  const [device, setDevice] = useState<Device | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [xmlOpen, setXmlOpen] = useState(false)
  const [xmlContent, setXmlContent] = useState<string | null>(null)
  const [xmlLoading, setXmlLoading] = useState(false)
  const [xmlError, setXmlError] = useState<string | null>(null)
  const [xmlCopied, setXmlCopied] = useState(false)

  // Fixed rolling window, resolved once on mount — matches the "last 7 days"
  // the chart titles promise. Resolved lazily so it is computed a single time.
  const [{ from, to }] = useState(() => rollingRange(24 * 7))

  const [tempData, setTempData] = useState<StatPoint[]>([])
  const [powerData, setPowerData] = useState<StatPoint[]>([])
  const [energyData, setEnergyData] = useState<StatPoint[]>([])
  const [voltageData, setVoltageData] = useState<StatPoint[]>([])
  const [standby, setStandby] = useState<Standby | null>(null)

  const loadDevice = async () => {
    if (!ain) return
    try {
      const d = await getDevice(ain)
      setDevice(d)
      setError(null)
    } catch (e) {
      setError(e instanceof Error ? e.message : t('detail.failedToLoad'))
    } finally {
      setLoading(false)
    }
  }

  const updateProtection = async (confirmOn: boolean, confirmOff: boolean) => {
    if (!ain) return
    await setDeviceProtection(ain, confirmOn, confirmOff)
    await loadDevice()
  }

  useEffect(() => {
    loadDevice()
  }, [ain])

  useEffect(() => {
    if (!ain) return
    getStats(ain, 'temperature', from, to).then((r) => setTempData(r.data)).catch(() => {})
    getStats(ain, 'power', from, to).then((r) => setPowerData(r.data)).catch(() => {})
    getStats(ain, 'energy', from, to).then((r) => setEnergyData(r.data)).catch(() => {})
    getStats(ain, 'voltage', from, to).then((r) => setVoltageData(r.data)).catch(() => {})
    // Null for a device with too little history to have a weekly floor — the row
    // is then simply absent, rather than reading 0 W.
    getStandby(ain).then(setStandby).catch(() => setStandby(null))
  }, [ain, from, to])

  const showXml = async () => {
    setXmlOpen(true)
    setXmlError(null)
    if (xmlContent !== null || !ain) return
    setXmlLoading(true)
    try {
      setXmlContent(await getDeviceXml(ain))
    } catch {
      setXmlError(t('device.xmlError'))
    } finally {
      setXmlLoading(false)
    }
  }

  const copyXml = async () => {
    if (!xmlContent) return
    await navigator.clipboard.writeText(xmlContent)
    setXmlCopied(true)
    setTimeout(() => setXmlCopied(false), 2000)
  }

  const downloadXml = () => {
    if (!xmlContent || !ain) return
    const blob = new Blob([xmlContent], { type: 'application/xml' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `fritzbox-device-${ain.replace(/\s+/g, '')}.xml`
    a.click()
    URL.revokeObjectURL(url)
  }

  if (loading) return <div className="loading-state">{t('common.loading')}</div>
  if (error || !device) return <div className="alert alert--danger">{error ?? t('device.notFound')}</div>

  /**
   * How the week was spent, in one line.
   *
   * Four distinct states, because "0 W" alone is ambiguous: a device that was
   * off all week and one that was on all week drawing nothing produce the same
   * floor, and only the duty cycle separates them. Rounded to whole percent
   * before comparing against 100, so 99.96% reads as "never switched off"
   * rather than claiming an idle draw the percentile did not really isolate.
   */
  const dutyNote = (s: Standby) => {
    const percent = formatNumber(s.dutyCyclePercent, i18n.language, 1)

    if (s.dutyCyclePercent <= 0) return t('energy.dutyOff')
    if (Math.round(s.dutyCyclePercent) >= 100) return t('energy.dutyAlways')

    return s.idleWatts === null
      ? t('energy.dutyPartialUnknown', { percent })
      : t('energy.dutyPartial', { percent, watts: formatWatts(s.idleWatts, i18n.language) })
  }

  // AVM's HKR diagnostics are codes 1-6. Anything else falls back to the bare
  // number rather than rendering a missing-translation key.
  const hkrErrorLabel = (code: number) => (code >= 1 && code <= 6
    ? t(`detail.hkrError${code}` as 'detail.hkrError1')
    : t('detail.hkrErrorUnknown', { code }))

  // Only surfaced while active, so an idle thermostat shows no status row at all.
  const th = device.thermostat
  const thermostatFlags = ([
    th?.windowOpen && 'detail.windowOpen',
    th?.boostActive && 'detail.boostActive',
    th?.holidayActive && 'detail.holidayActive',
    th?.summerActive && 'detail.summerActive',
  ] as const).filter((k): k is 'detail.windowOpen' => typeof k === 'string')

  return (
    <div className="page">
      <nav className="breadcrumb">
        <Link to="/dashboard" className="breadcrumb-back">
          <DashboardIcon size={18} />
          <svg className="breadcrumb-back-chevron" viewBox="0 0 16 16" fill="currentColor"><path d="M10.3 2.3a1 1 0 0 1 0 1.4L6.42 8l3.88 4.3a1 1 0 1 1-1.4 1.4l-4.6-5a1 1 0 0 1 0-1.4l4.6-5a1 1 0 0 1 1.4 0z"/></svg>
          {t('common.back')}
        </Link>
        <span className="breadcrumb-current">{device.name}</span>
      </nav>

      <PageHeader
        title={<span className="detail-title-row">{device.productImage
          ? <ProductImage src={device.productImage} alt={device.productName} size={28} eager />
          : <DeviceIcon functionBitMask={device.functionBitMask} size={22} />
        }{device.name}</span>}
        subtitle={<>{device.ain}{device.productName ? ` — ${device.productName}` : ''}{device.manufacturer ? ` (${device.manufacturer})` : ''}</>}
      />

      {device.productImage && (
        <div className="detail-hero">
          <div className="detail-hero-image">
            <ProductImage src={device.productImage} alt={device.productName} size={180} eager />
          </div>
          <div className="detail-hero-info">
            <div className="detail-hero-name">{device.name}</div>
            {device.productName && <div className="detail-hero-product">{device.productName}</div>}
            <div className="detail-hero-meta">
              {device.manufacturer && <span>{device.manufacturer}</span>}
              {device.firmwareVersion && <span>Firmware {device.firmwareVersion}</span>}
              <span>{device.ain}</span>
            </div>
            <div className="detail-hero-badges">
              <PresentBadge present={device.present} />
              {device.outlet && <OnOffBadge on={device.outlet.state === 'on'} />}
            </div>
          </div>
        </div>
      )}

      {!device.productImage && (
        <div className="detail-meta">
          <PresentBadge present={device.present} />
          {device.outlet && <OnOffBadge on={device.outlet.state === 'on'} />}
        </div>
      )}

      <div className="detail-grid">
        {device.features.outlet && device.outlet && (
          <Card title={t('detail.switch')}>
            <div className="detail-row">
              <span>{t('detail.state')}</span>
              <OutletToggle
                ain={device.ain}
                currentState={device.outlet.state}
                name={device.name}
                confirmOn={device.confirmOn}
                confirmOff={device.confirmOff}
                onToggled={loadDevice}
              />
            </div>
            <div className="detail-row">
              <span>{t('detail.mode')}</span>
              <span>{device.outlet.mode}</span>
            </div>
            {isAdmin && (
              <>
                <div className="detail-row">
                  <span>{t('detail.confirmOn')}</span>
                  <Switch
                    checked={device.confirmOn ?? false}
                    onChange={(next) => updateProtection(next, device.confirmOff ?? false)}
                    ariaLabel={t('detail.confirmOn')}
                  />
                </div>
                <div className="detail-row">
                  <span>{t('detail.confirmOff')}</span>
                  <Switch
                    checked={device.confirmOff ?? false}
                    onChange={(next) => updateProtection(device.confirmOn ?? false, next)}
                    ariaLabel={t('detail.confirmOff')}
                  />
                </div>
              </>
            )}
          </Card>
        )}

        {device.features.thermostat && device.thermostat && (
          <Card title={t('detail.thermostat')}>
            <div className="detail-row">
              <span>{t('detail.setpoint')}</span>
              {/* The slider stays available in off/max mode — setting a
                  temperature is how the valve is brought back under control. */}
              <SetpointControl ain={device.ain} currentSetpoint={device.thermostat.setpoint} onChanged={loadDevice} />
            </div>
            {device.thermostat.mode !== 'temperature' && (
              <div className="detail-row">
                <span>{t('detail.valveMode')}</span>
                <Badge
                  label={t(device.thermostat.mode === 'off' ? 'detail.valveOff' : 'detail.valveMax')}
                  variant={device.thermostat.mode === 'off' ? 'neutral' : 'warning'}
                />
              </div>
            )}
            {device.thermostat.comfort != null && (
              <div className="detail-row"><span>{t('detail.comfort')}</span><span>{device.thermostat.comfort} °C</span></div>
            )}
            {device.thermostat.saving != null && (
              <div className="detail-row"><span>{t('detail.saving')}</span><span>{device.thermostat.saving} °C</span></div>
            )}
            {device.thermostat.battery != null && (
              <div className="detail-row">
                <span>{t('detail.battery')}</span>
                {device.thermostat.batteryLow
                  ? <Badge label={`${device.thermostat.battery} %`} variant="danger" />
                  : <span>{device.thermostat.battery} %</span>}
              </div>
            )}
            {/* Older firmware reports only the flag, with no percentage. */}
            {device.thermostat.battery == null && device.thermostat.batteryLow != null && (
              <div className="detail-row">
                <span>{t('detail.battery')}</span>
                <Badge
                  label={t(device.thermostat.batteryLow ? 'detail.batteryLow' : 'detail.batteryOk')}
                  variant={device.thermostat.batteryLow ? 'danger' : 'success'}
                />
              </div>
            )}
            {thermostatFlags.length > 0 && (
              <div className="detail-row">
                <span>{t('detail.thermostatStatus')}</span>
                <span className="badge-row">
                  {thermostatFlags.map((f) => <Badge key={f} label={t(f)} variant="warning" />)}
                </span>
              </div>
            )}
            {device.thermostat.errorCode != null && device.thermostat.errorCode > 0 && (
              <div className="detail-row">
                <span>{t('detail.thermostatError')}</span>
                <Badge label={hkrErrorLabel(device.thermostat.errorCode)} variant="danger" />
              </div>
            )}
          </Card>
        )}

        {device.features.powerMeter && device.powerMeter && (
          <Card title={t('detail.powerMeter')}>
            <div className="detail-row"><span>{t('detail.voltage')}</span><span>{device.powerMeter.voltage} V</span></div>
            <div className="detail-row"><span>{t('detail.power')}</span><span>{device.powerMeter.power} W</span></div>
            <div className="detail-row"><span>{t('detail.energy')}</span><span>{formatEnergy(device.powerMeter.energy, i18n.language)}</span></div>
            {standby !== null && (
              <>
                <div className="detail-row">
                  <span>{t('energy.standby')}</span>
                  <span>{formatWatts(standby.watts, i18n.language)}</span>
                </div>
                {standby.annualCost !== null && (
                  <div className="detail-row">
                    <span>{t('energy.standbyAnnual')}</span>
                    <span>{formatCurrency(standby.annualCost, standby.currency, i18n.language)}</span>
                  </div>
                )}
                {/* The floor above is a round-the-clock figure, so it reads 0 W
                    for anything switched off — which is true and tells you
                    nothing about the appliance. This line separates the two
                    questions: how much of the week it was on, and what it drew
                    while it was. */}
                <div className="stat-tile-hint">{dutyNote(standby)}</div>
                <div className="stat-tile-hint">
                  {t('energy.standbyBasis', { days: standby.windowDays, samples: standby.samples })}
                </div>
              </>
            )}
          </Card>
        )}

        {device.temperature && (
          <Card title={t('detail.tempSensor')}>
            <div className="detail-row"><span>{t('detail.current')}</span><span>{device.temperature.celsius} °C</span></div>
            <div className="detail-row"><span>{t('detail.offset')}</span><span>{device.temperature.offset} °C</span></div>
          </Card>
        )}
      </div>

      <div className="charts-grid">
        {tempData.length > 0 && (
          <Card title={t('detail.chartTitle', { metric: t('chart.temperature') })}><TemperatureChart data={tempData} /></Card>
        )}
        {powerData.length > 0 && (
          <Card title={t('detail.chartTitle', { metric: t('chart.power') })}><PowerChart data={powerData} /></Card>
        )}
        {energyData.length > 0 && (
          <Card title={t('detail.chartTitle', { metric: t('chart.energy') })}><EnergyChart data={energyData} /></Card>
        )}
        {voltageData.length > 0 && (
          <Card title={t('detail.chartTitle', { metric: t('chart.voltage') })}><VoltageChart data={voltageData} /></Card>
        )}
      </div>

      <div className="xml-viewer">
        <Button variant="secondary" size="sm" onClick={showXml}>
          {t('device.showXml')}
        </Button>
      </div>

      <Modal open={xmlOpen} onClose={() => setXmlOpen(false)} title={t('device.showXml')}>
        {xmlLoading && <div className="loading-state">{t('common.loading')}</div>}
        {xmlError && <div className="alert alert--danger">{xmlError}</div>}
        {xmlContent && (
          <>
            <p className="xml-viewer-hint">{t('device.xmlHint')}</p>
            <div className="xml-viewer-toolbar">
              <Button variant="secondary" size="sm" onClick={copyXml}>
                {xmlCopied ? t('device.xmlCopied') : t('device.copyXml')}
              </Button>
              <Button variant="secondary" size="sm" onClick={downloadXml}>
                {t('device.downloadXml')}
              </Button>
            </div>
            <pre className="xml-viewer-code"><code>{xmlContent}</code></pre>
          </>
        )}
      </Modal>
    </div>
  )
}
