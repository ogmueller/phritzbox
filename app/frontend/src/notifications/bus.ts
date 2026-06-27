// Framework-agnostic notification bus. The API client (a plain module, not
// React) publishes here; NotificationHost subscribes and renders. Text is
// carried as an i18n key + params so the React host can translate it.

export type NotificationSeverity = 'error' | 'warning' | 'info' | 'success'

export interface AppNotification {
  id: number
  severity: NotificationSeverity
  /** Pre-resolved text. Takes precedence over messageKey. */
  message?: string
  /** i18n key, resolved by the host. */
  messageKey?: string
  params?: Record<string, unknown>
}

type Listener = (n: AppNotification) => void

const listeners = new Set<Listener>()
let nextId = 1

export function pushNotification(n: Omit<AppNotification, 'id'>): void {
  const notification: AppNotification = { id: nextId++, ...n }
  for (const listener of listeners) {
    listener(notification)
  }
}

export function subscribe(listener: Listener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}
