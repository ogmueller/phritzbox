import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, act, fireEvent } from '@testing-library/react'
import { NotificationHost } from './NotificationHost'
import { pushNotification } from '../../notifications/bus'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}))

describe('NotificationHost', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('renders a toast for a pushed notification', () => {
    render(<NotificationHost />)
    act(() => pushNotification({ severity: 'error', message: 'boom' }))
    expect(screen.getByText('boom')).toBeInTheDocument()
  })

  it('de-duplicates identical notifications with a count', () => {
    render(<NotificationHost />)
    act(() => {
      pushNotification({ severity: 'error', message: 'same' })
      pushNotification({ severity: 'error', message: 'same' })
      pushNotification({ severity: 'error', message: 'same' })
    })
    expect(screen.getAllByText('same')).toHaveLength(1)
    expect(screen.getByText('×3')).toBeInTheDocument()
  })

  it('auto-dismisses after the timeout', async () => {
    render(<NotificationHost />)
    act(() => pushNotification({ severity: 'error', message: 'temporary' }))
    expect(screen.getByText('temporary')).toBeInTheDocument()
    await act(async () => { vi.advanceTimersByTime(10_000) })
    expect(screen.queryByText('temporary')).toBeNull()
  })

  it('dismisses on the close button', () => {
    render(<NotificationHost />)
    act(() => pushNotification({ severity: 'error', message: 'closable' }))
    fireEvent.click(screen.getByRole('button', { name: 'errors.dismiss' }))
    expect(screen.queryByText('closable')).toBeNull()
  })
})
