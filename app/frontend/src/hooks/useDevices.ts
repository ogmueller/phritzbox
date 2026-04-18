import { useState, useEffect, useCallback } from 'react'
import { getDevices, Device } from '../api/devices'

export function useDevices(pollIntervalMs = 30_000) {
  const [devices, setDevices] = useState<Device[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const fetch = useCallback(async () => {
    try {
      const data = await getDevices()
      setDevices(data)
      setError(null)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load devices')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetch()
    const id = setInterval(fetch, pollIntervalMs)
    return () => clearInterval(id)
  }, [fetch, pollIntervalMs])

  return { devices, loading, error, refresh: fetch }
}
