import { api } from './client'

export interface Tariff {
  /** null means no tariff configured — distinct from a configured 0.00. */
  pricePerKwh: number | null
  standingChargeMonth: number
  currency: string
  configured: boolean
}

export interface TariffPayload {
  /** Empty string clears the tariff back to unconfigured. */
  pricePerKwh: string
  standingChargeMonth: string
  currency: string
}

export function getTariff(): Promise<Tariff> {
  return api.get<Tariff>('/api/settings/tariff')
}

export function updateTariff(payload: TariffPayload): Promise<Tariff> {
  return api.put<Tariff>('/api/settings/tariff', payload)
}
