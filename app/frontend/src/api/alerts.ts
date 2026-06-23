import { api } from './client'

export type AlertMode = 'threshold' | 'comparison'
export type AlertOperator = 'gt' | 'lt' | 'gte' | 'lte'

export interface Alert {
  id: number
  name: string
  enabled: boolean
  mode: AlertMode
  sid: string
  type: string
  operator: AlertOperator
  threshold: number | null
  compareSid: string | null
  compareType: string | null
  compareOffset: number
  durationMinutes: number
  channelIds: number[]
  channelNames: string[]
  cooldownMinutes: number
  lastState: string
  lastTriggeredAt: string | null
  createdAt: string
}

export interface AlertPayload {
  name: string
  enabled: boolean
  mode: AlertMode
  sid: string
  type: string
  operator: AlertOperator
  threshold?: number | null
  compareSid?: string | null
  compareType?: string | null
  compareOffset?: number
  durationMinutes: number
  channelIds: number[]
  cooldownMinutes: number
}

export function getAlerts(): Promise<Alert[]> {
  return api.get<Alert[]>('/api/alerts')
}

export function createAlert(payload: AlertPayload): Promise<Alert> {
  return api.post<Alert>('/api/alerts', payload)
}

export function updateAlert(id: number, payload: AlertPayload): Promise<Alert> {
  return api.put<Alert>(`/api/alerts/${id}`, payload)
}

export function deleteAlert(id: number): Promise<void> {
  return api.delete<void>(`/api/alerts/${id}`)
}

export function testAlert(id: number): Promise<{ status: string }> {
  return api.post<{ status: string }>(`/api/alerts/${id}/test`)
}

export function toggleAlert(id: number): Promise<Alert> {
  return api.post<Alert>(`/api/alerts/${id}/toggle`)
}
