import React, { createContext, useContext, useState, useCallback, useEffect } from 'react'
import { getDevices, Device } from '../api/devices'

interface DeviceContextValue {
  devices: Device[]
  loading: boolean
  error: string | null
  refresh: () => Promise<void>
}

const DeviceContext = createContext<DeviceContextValue | null>(null)

export function DeviceProvider({ children }: { children: React.ReactNode }) {
  const [devices, setDevices] = useState<Device[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const refresh = useCallback(async () => {
    try {
      const list = await getDevices()
      setDevices(list)
      setError(null)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load devices')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    refresh()
  }, [refresh])

  return (
    <DeviceContext.Provider value={{ devices, loading, error, refresh }}>
      {children}
    </DeviceContext.Provider>
  )
}

export function useDeviceContext(): DeviceContextValue {
  const ctx = useContext(DeviceContext)
  if (!ctx) throw new Error('useDeviceContext must be used inside DeviceProvider')
  return ctx
}
