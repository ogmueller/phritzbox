import { useCallback, useEffect, useState } from 'react'
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

  const dismiss = useCallback((id: number) => {
    setToasts((prev) => prev.filter((x) => x.id !== id))
  }, [])

  // Subscribe once; the handler only updates state (pure), so it's test-friendly.
  useEffect(() => {
    return subscribe((n) => {
      const text = n.message ?? (n.messageKey ? String(t(n.messageKey as never, n.params as never)) : '')
      if (!text) return

      setToasts((prev) => {
        // De-duplicate: an identical, still-visible toast bumps its count instead
        // of stacking a copy. The count change re-runs the timer effect below,
        // which resets its auto-dismiss countdown.
        const dupe = prev.find((x) => x.severity === n.severity && x.text === text)
        if (dupe) {
          return prev.map((x) => (x.id === dupe.id ? { ...x, count: x.count + 1 } : x))
        }
        return [...prev, { id: n.id, severity: n.severity, text, count: 1 }]
      })
    })
  }, [t])

  // Arm an auto-dismiss timer per visible toast; re-armed whenever the toast set
  // changes (including a dedup count bump). Cleared on unmount/change.
  useEffect(() => {
    const timers = toasts.map((toast) => setTimeout(() => dismiss(toast.id), AUTO_DISMISS_MS))
    return () => timers.forEach(clearTimeout)
  }, [toasts, dismiss])

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
