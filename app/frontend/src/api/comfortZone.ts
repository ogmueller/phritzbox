import { useEffect, useSyncExternalStore } from 'react'
import { api } from './client'

/** Colours temperature charts: blue up to `cold`, red from `warm`, a blend in between. */
export interface ComfortZone {
  cold: number
  warm: number
}

/** Mirrors ComfortZoneSettings::DEFAULT_* — what applies until the server answers. */
export const DEFAULT_COMFORT_ZONE: ComfortZone = { cold: 16, warm: 20 }

interface ComfortZoneResponse {
  coldBelow: number
  warmFrom: number
}

export interface ComfortZonePayload {
  /** Strings, as typed: the endpoint accepts a decimal comma. */
  coldBelow: string
  warmFrom: string
}

const fromResponse = (r: ComfortZoneResponse): ComfortZone => ({ cold: r.coldBelow, warm: r.warmFrom })

// One household-wide value shared by every chart on the page. Held here rather
// than fetched per chart, so a page of charts makes one request and a save on
// the Settings page recolours charts without a reload.
let current: ComfortZone = DEFAULT_COMFORT_ZONE
let requested = false
const listeners = new Set<() => void>()

function publish(zone: ComfortZone): void {
  current = zone
  listeners.forEach((l) => l())
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

export async function getComfortZone(): Promise<ComfortZone> {
  const zone = fromResponse(await api.get<ComfortZoneResponse>('/api/settings/comfort-zone'))
  publish(zone)
  return zone
}

export async function updateComfortZone(payload: ComfortZonePayload): Promise<ComfortZone> {
  const zone = fromResponse(await api.put<ComfortZoneResponse>('/api/settings/comfort-zone', payload))
  publish(zone)
  return zone
}

/** The household comfort zone; the defaults until the first load answers, or if it fails. */
export function useComfortZone(): ComfortZone {
  useEffect(() => {
    if (requested) return
    requested = true
    getComfortZone().catch(() => { requested = false })
  }, [])
  return useSyncExternalStore(subscribe, () => current)
}
