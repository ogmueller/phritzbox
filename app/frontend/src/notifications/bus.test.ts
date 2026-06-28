import { describe, it, expect } from 'vitest'
import { pushNotification, subscribe, AppNotification } from './bus'

describe('notifications bus', () => {
  it('delivers notifications to subscribers with unique ids', () => {
    const received: AppNotification[] = []
    const unsub = subscribe((n) => received.push(n))

    pushNotification({ severity: 'error', messageKey: 'errors.server' })
    pushNotification({ severity: 'info', message: 'hi' })

    expect(received).toHaveLength(2)
    expect(received[0].id).not.toBe(received[1].id)
    expect(received[1]).toMatchObject({ severity: 'info', message: 'hi' })

    unsub()
    pushNotification({ severity: 'error', message: 'after-unsub' })
    expect(received).toHaveLength(2)
  })

  it('fans out to multiple subscribers', () => {
    const a: AppNotification[] = []
    const b: AppNotification[] = []
    const ua = subscribe((n) => a.push(n))
    const ub = subscribe((n) => b.push(n))

    pushNotification({ severity: 'warning', message: 'x' })

    expect(a).toHaveLength(1)
    expect(b).toHaveLength(1)
    ua()
    ub()
  })
})
