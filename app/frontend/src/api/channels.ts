import { api } from './client'

export type ChannelType = 'email' | 'webhook' | 'pushover' | 'telegram' | 'ntfy' | 'discord' | 'gotify' | 'slack'

export interface Channel {
  id: number
  name: string
  type: ChannelType
  target: string
  secret: string | null
  enabled: boolean
  createdAt: string
}

export interface ChannelPayload {
  name: string
  type: ChannelType
  target: string
  secret?: string | null
  enabled: boolean
}

export function getChannels(): Promise<Channel[]> {
  return api.get<Channel[]>('/api/channels')
}

export function createChannel(payload: ChannelPayload): Promise<Channel> {
  return api.post<Channel>('/api/channels', payload)
}

export function updateChannel(id: number, payload: ChannelPayload): Promise<Channel> {
  return api.put<Channel>(`/api/channels/${id}`, payload)
}

export function deleteChannel(id: number): Promise<void> {
  return api.delete<void>(`/api/channels/${id}`)
}
