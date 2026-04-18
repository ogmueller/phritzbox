import { useTranslation } from 'react-i18next'
import { TimeSeriesChart } from './TimeSeriesChart'
import { StatPoint } from '../../api/stats'

export function VoltageChart({ data }: { data: StatPoint[] }) {
  const { t } = useTranslation()
  return <TimeSeriesChart data={data} label={t('chart.voltage')} unit="V" color="#6B7280" />
}
