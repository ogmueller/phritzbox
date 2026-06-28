import { api } from './client'

export interface Health {
  lastCollectedAt: string | null
  ageMinutes: number | null
}

export function getHealth(): Promise<Health> {
  return api.get<Health>('/api/health')
}
