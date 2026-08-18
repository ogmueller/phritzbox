import { describe, it, expect, vi, beforeEach } from 'vitest'
import { StrictMode } from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SettingsPage } from './SettingsPage'

/**
 * The app runs under React.StrictMode, which mounts every component twice in
 * development. Both mounts fire the load effect, so two GETs are in flight and
 * either may answer late.
 *
 * Without a cancellation guard the late answer calls setForm and overwrites
 * whatever the operator has typed in the meantime — and because a blank price
 * means "clear the tariff", the following Save silently wipes it and still
 * reports success.
 */
const getTariff = vi.fn()
const updateTariff = vi.fn()

vi.mock('../api/settings', () => ({
  getTariff: () => getTariff(),
  updateTariff: (p: unknown) => updateTariff(p),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, vars?: Record<string, unknown>) => (vars ? `${k} ${Object.values(vars).join(' ')}` : k),
    i18n: { language: 'en' },
  }),
}))

const UNSET = { pricePerKwh: null, standingChargeMonth: 0, currency: 'EUR', configured: false }

describe('SettingsPage under StrictMode double-mount', () => {
  beforeEach(() => {
    getTariff.mockReset()
    updateTariff.mockReset()
    updateTariff.mockResolvedValue({ ...UNSET, pricePerKwh: 0.35, configured: true })
  })

  it('keeps what was typed when the second load answers late', async () => {
    // First mount answers immediately; the second is still in flight while the
    // operator types.
    let releaseSecond: (() => void) | undefined
    let call = 0
    getTariff.mockImplementation(() => {
      call += 1
      if (call === 1) return Promise.resolve(UNSET)

      return new Promise((resolve) => { releaseSecond = () => resolve(UNSET) })
    })

    render(<StrictMode><SettingsPage /></StrictMode>)

    await waitFor(() => expect(getTariff).toHaveBeenCalledTimes(2))

    const price = () => screen.getByLabelText('settings.pricePerKwh') as HTMLInputElement
    await userEvent.type(price(), '0.35')
    expect(price().value).toBe('0.35')

    // The slow response lands now. Type one more character afterwards so the
    // assertion runs on a settled render rather than racing the flush.
    releaseSecond?.()
    await userEvent.type(price(), '0')
    expect(price().value).toBe('0.350')
  })

  it('saves the typed price rather than a blanked form', async () => {
    let releaseSecond: (() => void) | undefined
    let call = 0
    getTariff.mockImplementation(() => {
      call += 1
      if (call === 1) return Promise.resolve(UNSET)

      return new Promise((resolve) => { releaseSecond = () => resolve(UNSET) })
    })

    render(<StrictMode><SettingsPage /></StrictMode>)
    await waitFor(() => expect(getTariff).toHaveBeenCalledTimes(2))

    await userEvent.type(screen.getByLabelText('settings.pricePerKwh'), '0.35')
    releaseSecond?.()
    await userEvent.click(screen.getByText('common.save'))

    // The exact failure the operator hit: "Saved" shown, price gone.
    expect(updateTariff).toHaveBeenCalledWith({
      pricePerKwh: '0.35',
      standingChargeMonth: '',
      currency: 'EUR',
    })
  })

  it('keeps the saved price when a load from before the save answers after it', async () => {
    // The same stale answer, one beat later. It carries the tariff as it was
    // *before* the save, so letting it land empties a field the operator has
    // just watched the server accept — and the next Save then clears the tariff
    // for real.
    let releaseSecond: (() => void) | undefined
    let call = 0
    getTariff.mockImplementation(() => {
      call += 1
      if (call === 1) return Promise.resolve(UNSET)

      return new Promise((resolve) => { releaseSecond = () => resolve(UNSET) })
    })

    render(<StrictMode><SettingsPage /></StrictMode>)
    await waitFor(() => expect(getTariff).toHaveBeenCalledTimes(2))

    const price = () => screen.getByLabelText('settings.pricePerKwh') as HTMLInputElement
    await userEvent.type(price(), '0.35')
    await userEvent.click(screen.getByText('common.save'))
    await waitFor(() => expect(price().value).toBe('0.35'))

    releaseSecond?.()

    await waitFor(() => expect(screen.queryByText('settings.saved')).toBeTruthy())
    expect(price().value).toBe('0.35')
    expect(screen.queryByText('settings.notConfigured')).toBeNull()
  })
})
