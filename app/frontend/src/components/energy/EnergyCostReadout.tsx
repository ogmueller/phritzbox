import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { getEnergyCost, EnergyCost } from '../../api/energy'
import { Card } from '../../components/ui/Card'
import { formatCurrency, formatEnergy } from '../../format'

interface EnergyCostReadoutProps {
  ain: string
  from: string
  to: string
}

/**
 * Cost of the energy window currently on screen.
 *
 * Fetched from the server rather than summed from the chart's own points: the
 * coverage figures are the whole point (a total over days that are missing is a
 * lower bound, and the reader deserves to know), and neither the household share
 * nor the estimated-today flag can be derived in the browser.
 */
export function EnergyCostReadout({ ain, from, to }: EnergyCostReadoutProps) {
  const { t, i18n } = useTranslation()
  const [cost, setCost] = useState<EnergyCost | null>(null)

  useEffect(() => {
    let active = true
    getEnergyCost(from, to)
      .then((c) => { if (active) setCost(c) })
      .catch(() => { if (active) setCost(null) })

    return () => { active = false }
  }, [from, to])

  if (cost === null) return null

  const device = cost.devices.find((d) => d.ain === ain)
  if (device === undefined) {
    return (
      <Card title={t('energy.energyCost')}>
        <div className="cost-note">{t('energy.noData')}</div>
      </Card>
    )
  }

  const share = cost.household.energyWh > 0
    ? Math.round((device.energyWh / cost.household.energyWh) * 100)
    : null

  return (
    <Card title={t('energy.energyCost')}>
      <div className="detail-row">
        <span>{t('energy.rangeEnergy')}</span>
        <span>{formatEnergy(device.energyWh, i18n.language)}</span>
      </div>
      <div className="detail-row">
        <span>{t('energy.deviceCost')}</span>
        <span>{formatCurrency(device.cost, cost.currency, i18n.language)}</span>
      </div>
      {share !== null && (
        <div className="detail-row">
          <span>{t('energy.householdShare')}</span>
          <span>{t('energy.sharePercent', { percent: share })}</span>
        </div>
      )}
      <div className="detail-row">
        <span>{t('energy.householdTotal')}</span>
        <span>{formatCurrency(cost.household.cost, cost.currency, i18n.language)}</span>
      </div>

      {!cost.configured && <div className="cost-note">{t('energy.noTariff')}</div>}

      <div className="cost-note">
        {t('energy.coverageNote', {
          days: device.coverage.daysWithData,
          total: device.coverage.daysInRange,
        })}
      </div>

      {/* Only when days are genuinely lost — not when a device simply did not
          exist for the whole window, which would warn on every new device. */}
      {device.coverage.gapDays > 0 && (
        <div className="cost-note">{t('energy.gapWarning', { gaps: device.coverage.gapDays })}</div>
      )}

      {device.coverage.estimatedTodayWh > 0 && (
        <div className="cost-note">{t('energy.todayEstimated')}</div>
      )}

      {/* Self-retiring: shown only for a window that actually straddles the
          collector's day-label change. */}
      {cost.spansCollectorChange && (
        <div className="cost-note">{t('energy.dayShiftNote')}</div>
      )}
    </Card>
  )
}
