import { useTranslation } from 'react-i18next'
import { TimeSeriesChart } from './TimeSeriesChart'
import { StatPoint } from '../../api/stats'

export function EnergyChart({ data }: { data: StatPoint[] }) {
  const { t } = useTranslation()
  return <TimeSeriesChart data={data} label={t('chart.energy')} unit="Wh" color="#5A9E3A" />
}
