import { api } from './client'

export interface Health {
  lastCollectedAt: string | null
  ageMinutes: number | null
  /** null when the scheduled job has never run — see the cronado labels. */
  lastRollupAt: string | null
  lastBackupAt: string | null
}

export function getHealth(): Promise<Health> {
  return api.get<Health>('/api/health')
}
