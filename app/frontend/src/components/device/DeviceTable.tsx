import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Device } from '../../api/devices'
import { DataTable } from '../ui/DataTable'
import { DeviceIcon } from './DeviceIcon'
import { ProductImage } from './ProductImage'
import { PresentBadge, OnOffBadge } from '../ui/Badge'
import { OutletToggle } from './OutletToggle'
import { Button } from '../ui/Button'

interface DeviceTableProps {
  devices: Device[]
  onRefresh: () => void
}

export function DeviceTable({ devices, onRefresh }: DeviceTableProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()

  return (
    <DataTable
      rows={devices}
      keyFn={(d) => d.ain}
      emptyMessage={t('device.noDevices')}
      columns={[
        {
          key: 'name',
          header: t('device.device'),
          render: (d) => (
            <div className="device-name-cell">
              {d.productImage
                ? <ProductImage src={d.productImage} alt={d.productName} size={32} />
                : <DeviceIcon functionBitMask={d.functionBitMask} />
              }
              <div>
                <div className="device-name">{d.name}</div>
                <div className="device-ain">{d.ain}</div>
              </div>
            </div>
          ),
        },
        {
          key: 'status',
          header: t('device.status'),
          width: '120px',
          render: (d) => <PresentBadge present={d.present} />,
        },
        {
          key: 'switch',
          header: t('device.switch'),
          width: '80px',
          render: (d) =>
            d.features.outlet && d.outlet ? (
              <OnOffBadge on={d.outlet.state === 'on'} />
            ) : (
              <span className="text-muted">—</span>
            ),
        },
        {
          key: 'temp',
          header: t('device.temperature'),
          width: '110px',
          render: (d) =>
            d.temperature ? (
              <span>{d.temperature.celsius} °C</span>
            ) : d.thermostat?.setpoint != null ? (
              <span>{d.thermostat.setpoint} °C</span>
            ) : (
              <span className="text-muted">—</span>
            ),
        },
        {
          key: 'power',
          header: t('device.power'),
          width: '90px',
          render: (d) =>
            d.powerMeter ? (
              <span>{d.powerMeter.power} W</span>
            ) : (
              <span className="text-muted">—</span>
            ),
        },
        {
          key: 'actions',
          header: '',
          width: '180px',
          render: (d) => (
            <div className="row-actions">
              {d.features.outlet && d.outlet && (
                <OutletToggle ain={d.ain} currentState={d.outlet.state} onToggled={onRefresh} />
              )}
              <Button variant="ghost" size="sm" onClick={() => navigate(`/devices/${d.ain}`)}>
                {t('common.details')}
              </Button>
            </div>
          ),
        },
      ]}
    />
  )
}
