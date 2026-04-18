import { useState, useEffect } from 'react'
import { getStats, StatsResponse } from '../api/stats'

export function useStats(ain: string, type: string, from: string, to: string) {
  const [data, setData] = useState<StatsResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!ain || !type || !from || !to) return
    setLoading(true)
    getStats(ain, type, from, to)
      .then((res) => { setData(res); setError(null) })
      .catch((e) => setError(e instanceof Error ? e.message : 'Failed to load stats'))
      .finally(() => setLoading(false))
  }, [ain, type, from, to])

  return { data, loading, error }
}
