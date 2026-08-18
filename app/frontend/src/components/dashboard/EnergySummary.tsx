import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { getEnergySummary, EnergySummary as Summary } from '../../api/energy'
import { useDeviceContext } from '../../contexts/DeviceContext'
import { useAuth } from '../../contexts/AuthContext'
import { StatTile } from '../ui/StatTile'
import { formatCurrency, formatEnergy, formatWatts } from '../../format'

/**
 * Four headline figures above the device table.
 *
 * Live wattage is summed from the device list the page already has, so only one
 * request is made — the other three figures come from `/api/energy/summary`,
 * which aggregates server-side rather than making the browser ask per device.
 */
export function EnergySummary() {
  const { t, i18n } = useTranslation()
  const { devices } = useDeviceContext()
  const { isAdmin } = useAuth()
  const [summary, setSummary] = useState<Summary | null>(null)

  useEffect(() => {
    // Best-effort: the dashboard's device table must not disappear because a
    // cost figure could not be produced.
    const load = () => { getEnergySummary().then(setSummary).catch(() => {}) }
    load()

    // Slower than the device poll on purpose — these figures are day-grained,
    // so a minute is plenty. It is not only about freshness: without it a
    // dashboard left open keeps offering "set a tariff" long after one was set.
    const id = setInterval(load, 60_000)

    return () => clearInterval(id)
  }, [])

  // Only devices that actually report power contribute. When none do — the
  // cached-device fallback sets powerMeter to null — show the em dash rather
  // than a confident 0 W.
  const reporting = devices.filter((d) => d.powerMeter != null)
  const liveWatts = reporting.length === 0
    ? null
    : reporting.reduce((sum, d) => sum + (d.powerMeter?.power ?? 0), 0)

  const currency = summary?.currency ?? 'EUR'
  const configured = summary?.configured ?? false

  const month = summary?.monthToDate
  const monthEnergy = formatEnergy(month?.energyWh, i18n.language)

  /**
   * What the month's headline is made of.
   *
   * It adds the standing charge to the energy cost, which is why it can be
   * several times what the kilowatt-hours alone are worth — say so rather than
   * leave the arithmetic to be guessed at.
   */
  const monthBreakdown = (month?.standingCost ?? 0) > 0
    ? t('energy.includesStanding', {
      energy: monthEnergy,
      amount: formatCurrency(month?.standingCost, currency, i18n.language),
    })
    : monthEnergy

  /**
   * Shown only once the answer is in. `configured` defaults to false while the
   * request is in flight, so keying off it alone asks every operator to set a
   * tariff for the half second before the server says they already have.
   */
  const tariffPrompt = summary === null
    ? undefined
    : isAdmin
      ? (
        <Link to="/settings" className="stat-tile-cta">
          {t('energy.configureTariff')}
          <span className="stat-tile-cta-arrow" aria-hidden="true">→</span>
        </Link>
      )
      : t('energy.noTariffShort')

  /**
   * What a normal day looks like, so the figure above means something.
   *
   * Daily household energy swings by more than 2x here, so "625 Wh" on its own
   * says nothing about whether today is high or low. Deliberately not phrased as
   * a comparison ("40% below average"): today is a partial day, so any such
   * claim is unfair until midnight. State the baseline, let the reader judge.
   */
  const week = summary?.week
  const weekAverage = week?.averageWhPerDay ?? null
  const weekNote = week === undefined || weekAverage === null
    ? null
    : t(week.daysWithData === 7 ? 'energy.weekAverage' : 'energy.weekAveragePartial', {
      amount: formatEnergy(weekAverage, i18n.language),
      days: week.daysWithData,
    })

  // The estimate note qualifies the figure itself, so it stays closest to it;
  // the baseline is context and follows.
  const estimatedNote = summary?.today.estimated ? t('energy.estimated') : null
  const todayHint = estimatedNote !== null || weekNote !== null
    ? <>{estimatedNote}{weekNote !== null && <div>{weekNote}</div>}</>
    : undefined

  // A missing day makes every total a lower bound, so the warn border gets a
  // reason next to it whether or not a tariff turned the figure into money.
  const gapNote = (month?.gapDays ?? 0) > 0
    ? <div>{t('energy.gapWarning', { gaps: month?.gapDays })}</div>
    : null
  const monthLead = configured ? monthBreakdown : tariffPrompt
  const monthHint = monthLead || gapNote ? <>{monthLead}{gapNote}</> : undefined

  return (
    <div className="stat-grid">
      <StatTile
        label={t('energy.liveNow')}
        value={formatWatts(liveWatts, i18n.language)}
        hint={reporting.length > 0 ? t('energy.acrossDevices', { count: reporting.length }) : undefined}
      />
      <StatTile
        label={t('energy.today')}
        value={formatEnergy(summary?.today.energyWh, i18n.language)}
        // The box reports energy once a day, so today is integrated from power
        // until it does. Saying so keeps the figure honest.
        hint={todayHint}
      />
      <StatTile
        label={t('energy.monthToDate')}
        // With a tariff the tile is about money and the energy is the footnote.
        // Without one there is no money to show, so the energy is promoted to
        // the headline rather than leaving the figure slot to a link — the tile
        // then always states something it actually knows.
        value={configured ? formatCurrency(month?.cost, currency, i18n.language) : monthEnergy}
        hint={monthHint}
        tone={gapNote ? 'warn' : 'normal'}
      />
      <StatTile
        label={t('energy.standby')}
        value={formatWatts(summary?.standby.watts, i18n.language)}
        // Explicitly hypothetical: what a year at this floor would cost.
        hint={configured && summary?.standby.annualCost != null
          ? t('energy.standbyPerYear', {
            amount: formatCurrency(summary.standby.annualCost, currency, i18n.language),
          })
          : undefined}
      />
    </div>
  )
}
