import { useParams, Link } from 'react-router-dom'
import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { getDevice, getDeviceXml, Device } from '../api/devices'
import { getStats, StatPoint } from '../api/stats'
import { PageHeader } from '../components/layout/PageHeader'
import { Button } from '../components/ui/Button'
import { Modal } from '../components/ui/Modal'
import { Card } from '../components/ui/Card'
import { DashboardIcon } from '../components/ui/NavIcons'
import { PresentBadge, OnOffBadge } from '../components/ui/Badge'
import { OutletToggle } from '../components/device/OutletToggle'
import { SetpointControl } from '../components/device/SetpointControl'
import { DeviceIcon } from '../components/device/DeviceIcon'
import { ProductImage } from '../components/device/ProductImage'
import { TemperatureChart } from '../components/charts/TemperatureChart'
import { PowerChart } from '../components/charts/PowerChart'
import { EnergyChart } from '../components/charts/EnergyChart'
import { VoltageChart } from '../components/charts/VoltageChart'

function isoDate(d: Date) {
  return d.toISOString().slice(0, 10)
}

export function DeviceDetailPage() {
  const { t } = useTranslation()
  const { ain } = useParams<{ ain: string }>()
  const [device, setDevice] = useState<Device | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [xmlOpen, setXmlOpen] = useState(false)
  const [xmlContent, setXmlContent] = useState<string | null>(null)
  const [xmlLoading, setXmlLoading] = useState(false)
  const [xmlError, setXmlError] = useState<string | null>(null)
  const [xmlCopied, setXmlCopied] = useState(false)

  const today = new Date()
  const weekAgo = new Date(today)
  weekAgo.setDate(today.getDate() - 7)
  const [from] = useState(isoDate(weekAgo))
  const [to] = useState(isoDate(today))

  const [tempData, setTempData] = useState<StatPoint[]>([])
  const [powerData, setPowerData] = useState<StatPoint[]>([])
  const [energyData, setEnergyData] = useState<StatPoint[]>([])
  const [voltageData, setVoltageData] = useState<StatPoint[]>([])

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

  useEffect(() => {
    loadDevice()
  }, [ain])

  useEffect(() => {
    if (!ain) return
    getStats(ain, 'temperature', from, to).then((r) => setTempData(r.data)).catch(() => {})
    getStats(ain, 'power', from, to).then((r) => setPowerData(r.data)).catch(() => {})
    getStats(ain, 'energy', from, to).then((r) => setEnergyData(r.data)).catch(() => {})
    getStats(ain, 'voltage', from, to).then((r) => setVoltageData(r.data)).catch(() => {})
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

  if (loading) return <div className="loading-state">{t('common.loading')}</div>
  if (error || !device) return <div className="alert alert--danger">{error ?? t('device.notFound')}</div>

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
              <OutletToggle ain={device.ain} currentState={device.outlet.state} onToggled={loadDevice} />
            </div>
            <div className="detail-row">
              <span>{t('detail.mode')}</span>
              <span>{device.outlet.mode}</span>
            </div>
          </Card>
        )}

        {device.features.thermostat && device.thermostat && (
          <Card title={t('detail.thermostat')}>
            <div className="detail-row">
              <span>{t('detail.setpoint')}</span>
              <SetpointControl ain={device.ain} currentSetpoint={device.thermostat.setpoint} onChanged={loadDevice} />
            </div>
            {device.thermostat.comfort != null && (
              <div className="detail-row"><span>{t('detail.comfort')}</span><span>{device.thermostat.comfort} °C</span></div>
            )}
            {device.thermostat.saving != null && (
              <div className="detail-row"><span>{t('detail.saving')}</span><span>{device.thermostat.saving} °C</span></div>
            )}
          </Card>
        )}

        {device.features.powerMeter && device.powerMeter && (
          <Card title={t('detail.powerMeter')}>
            <div className="detail-row"><span>{t('detail.voltage')}</span><span>{device.powerMeter.voltage} V</span></div>
            <div className="detail-row"><span>{t('detail.power')}</span><span>{device.powerMeter.power} W</span></div>
            <div className="detail-row"><span>{t('detail.energy')}</span><span>{device.powerMeter.energy} Wh</span></div>
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
            <div className="xml-viewer-toolbar">
              <Button variant="secondary" size="sm" onClick={copyXml}>
                {xmlCopied ? t('device.xmlCopied') : t('device.copyXml')}
              </Button>
            </div>
            <pre className="xml-viewer-code"><code>{xmlContent}</code></pre>
          </>
        )}
      </Modal>
    </div>
  )
}
