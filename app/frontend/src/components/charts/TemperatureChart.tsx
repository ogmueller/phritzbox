import { useTranslation } from 'react-i18next'
import { TimeSeriesChart } from './TimeSeriesChart'
import { StatPoint } from '../../api/stats'

export function TemperatureChart({ data }: { data: StatPoint[] }) {
  const { t } = useTranslation()
  return <TimeSeriesChart data={data} label={t('chart.temperature')} unit="°C" color="#E8620D" />
}
