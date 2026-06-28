import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { getHealth } from '../../api/health'

// Data is collected every ~30 min; warn once we're well past two intervals.
const STALE_THRESHOLD_MIN = 70
const POLL_MS = 5 * 60_000

export function StaleDataBanner() {
  const { t } = useTranslation()
  const [ageMinutes, setAgeMinutes] = useState<number | null>(null)

  useEffect(() => {
    let cancelled = false
    const check = () => getHealth().then((h) => { if (!cancelled) setAgeMinutes(h.ageMinutes) }).catch(() => {})
    check()
    const id = setInterval(check, POLL_MS)
    return () => { cancelled = true; clearInterval(id) }
  }, [])

  if (ageMinutes === null || ageMinutes <= STALE_THRESHOLD_MIN) return null

  return (
    <div className="alert alert--warning stale-banner" role="status">
      {t('banner.staleData', { minutes: ageMinutes })}
    </div>
  )
}
