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
