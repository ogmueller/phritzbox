import { useTranslation } from 'react-i18next'
import { TimeSeriesChart } from './TimeSeriesChart'
import { StatPoint } from '../../api/stats'

export function PowerChart({ data }: { data: StatPoint[] }) {
  const { t } = useTranslation()
  return <TimeSeriesChart data={data} label={t('chart.power')} unit="W" color="#005A8B" />
}
