import { api } from './client'

export interface Coverage {
  daysInRange: number
  daysWithData: number
  gapDays: number
  zeroDays?: number
  firstDay?: string | null
  lastDay?: string | null
  estimatedTodayWh: number
}

export interface DeviceCost {
  ain: string
  name: string
  energyWh: number
  /** null when no tariff is configured. */
  cost: number | null
  coverage: Coverage
}

export interface HouseholdCost {
  energyWh: number
  energyCost: number | null
  /** Billed per connection, so it exists only at household level. */
  standingCost: number | null
  cost: number | null
  coverage: Coverage
}

export interface EnergyCost {
  from: string
  to: string
  currency: string
  pricePerKwh: number | null
  configured: boolean
  /** True only for a window straddling the collector's day-label change. */
  spansCollectorChange: boolean
  devices: DeviceCost[]
  household: HouseholdCost
}

export interface Standby {
  /** The round-the-clock floor: what it draws whether on or off. */
  watts: number
  annualKwh: number
  /** null when no tariff is configured. */
  annualCost: number | null
  currency: string
  /** Share of the window the device drew anything at all. 0 = never came on. */
  dutyCyclePercent: number
  /**
   * The floor it holds *while switched on* — what most people mean by standby
   * for an appliance that spends part of the week off. null when it was never
   * on, or was on too briefly for a percentile to mean anything.
   */
  idleWatts: number | null
  samples: number
  windowDays: number
  source: 'rollup' | 'raw'
}

export interface EnergySummary {
  currency: string
  configured: boolean
  today: { energyWh: number; cost: number | null; estimated: boolean }
  monthToDate: { energyWh: number; energyCost: number | null; standingCost: number | null; cost: number | null; gapDays: number }
  topConsumer: { ain: string; name: string; energyWh: number; cost: number | null } | null
  standby: { watts: number; annualKwh: number; annualCost: number | null; devices: number }
}

export function getEnergyCost(from: string, to: string): Promise<EnergyCost> {
  const params = new URLSearchParams({ from, to })
  return api.get<EnergyCost>(`/api/energy/cost?${params}`)
}

export function getEnergySummary(): Promise<EnergySummary> {
  return api.get<EnergySummary>('/api/energy/summary')
}

/**
 * One device's idle draw, or null when the window holds too few samples to quote
 * a figure — the endpoint answers 204 rather than inventing a zero, and the
 * client turns a 204 body into undefined.
 */
export function getStandby(ain: string): Promise<Standby | null> {
  return api
    .get<Standby | undefined>(`/api/energy/standby/${encodeURIComponent(ain)}`)
    .then((s) => s ?? null)
}
