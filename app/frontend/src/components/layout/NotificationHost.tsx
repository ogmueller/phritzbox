import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AppNotification, subscribe } from '../../notifications/bus'

const AUTO_DISMISS_MS = 10_000

interface Toast {
  id: number
  severity: AppNotification['severity']
  text: string
  count: number
}

export function NotificationHost() {
  const { t } = useTranslation()
  const [toasts, setToasts] = useState<Toast[]>([])
  // Per-toast dismissal timers, keyed by toast id.
  const timers = useRef<Map<number, ReturnType<typeof setTimeout>>>(new Map())

  const dismiss = (id: number) => {
    const timer = timers.current.get(id)
    if (timer) {
      clearTimeout(timer)
      timers.current.delete(id)
    }
    setToasts((prev) => prev.filter((x) => x.id !== id))
  }

  const arm = (id: number) => {
    const existing = timers.current.get(id)
    if (existing) clearTimeout(existing)
    timers.current.set(id, setTimeout(() => dismiss(id), AUTO_DISMISS_MS))
  }

  useEffect(() => {
    const unsubscribe = subscribe((n) => {
      const text = n.message ?? (n.messageKey ? String(t(n.messageKey as never, n.params as never)) : '')
      if (!text) return

      setToasts((prev) => {
        // De-duplicate: an identical, still-visible toast bumps its count and
        // re-arms its timer instead of stacking another copy.
        const dupe = prev.find((x) => x.severity === n.severity && x.text === text)
        if (dupe) {
          arm(dupe.id)
          return prev.map((x) => (x.id === dupe.id ? { ...x, count: x.count + 1 } : x))
        }
        arm(n.id)
        return [...prev, { id: n.id, severity: n.severity, text, count: 1 }]
      })
    })

    const pending = timers.current
    return () => {
      unsubscribe()
      pending.forEach((timer) => clearTimeout(timer))
      pending.clear()
    }
  }, [t])

  if (toasts.length === 0) return null

  return (
    <div className="toast-host" role="status" aria-live="polite">
      {toasts.map((toast) => (
        <div key={toast.id} className={`toast toast--${toast.severity}`}>
          <span className="toast__text">{toast.text}</span>
          {toast.count > 1 && <span className="toast__count">×{toast.count}</span>}
          <button
            type="button"
            className="toast__close"
            aria-label={t('errors.dismiss')}
            onClick={() => dismiss(toast.id)}
          >
            ✕
          </button>
        </div>
      ))}
    </div>
  )
}
