import { api } from './client'

export interface StatPoint {
  time: string
  value: number
  type: string
}

export interface StatsResponse {
  ain: string
  type: string
  data: StatPoint[]
}

export function getStats(ain: string, type: string, from: string, to: string): Promise<StatsResponse> {
  const params = new URLSearchParams({ type, from, to })
  return api.get<StatsResponse>(`/api/stats/${encodeURIComponent(ain)}?${params}`)
}

export function getStatTypes(ain: string): Promise<{ ain: string; types: string[] }> {
  return api.get<{ ain: string; types: string[] }>(`/api/stats/types/${encodeURIComponent(ain)}`)
}

export interface RefreshResponse {
  status: string
  devices: number
  rows: number
  alerts: { rules: number; triggered: number; notified: number; resolved: number } | null
}

export function refreshStats(): Promise<RefreshResponse> {
  return api.post<RefreshResponse>('/api/stats/refresh')
}
