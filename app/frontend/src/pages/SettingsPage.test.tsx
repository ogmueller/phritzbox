import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SettingsPage } from './SettingsPage'

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

const tariff = (over: Record<string, unknown> = {}) => ({
  pricePerKwh: 0.35, standingChargeMonth: 12, currency: 'EUR', configured: true, ...over,
})

const price = () => screen.getByLabelText('settings.pricePerKwh') as HTMLInputElement
const standing = () => screen.getByLabelText('settings.standingCharge') as HTMLInputElement
const currency = () => screen.getByLabelText('settings.currency') as HTMLSelectElement

describe('SettingsPage', () => {
  beforeEach(() => {
    getTariff.mockReset()
    updateTariff.mockReset()
  })

  it('fills the form from the stored tariff', async () => {
    getTariff.mockResolvedValue(tariff())

    render(<SettingsPage />)

    await waitFor(() => expect(price().value).toBe('0.35'))
    expect(standing().value).toBe('12')
    expect(currency().value).toBe('EUR')
    expect(screen.queryByText('settings.notConfigured')).toBeNull()
  })

  it('leaves the price blank when nothing is configured, rather than showing 0', async () => {
    // "" and "0" mean different things here: 0 is a legitimate tariff (own solar).
    getTariff.mockResolvedValue(tariff({ pricePerKwh: null, standingChargeMonth: 0, configured: false }))

    render(<SettingsPage />)

    expect(await screen.findByText('settings.notConfigured')).toBeTruthy()
    expect(price().value).toBe('')
    expect(standing().value).toBe('')
  })

  it('marks the example price as an example rather than showing a bare number', async () => {
    // The failure this guards: an untouched field showing "0.35" reads as a
    // configured tariff, so Save is pressed on an empty form and stores nothing.
    // A localised, prefixed placeholder says what it is even before the styling
    // does — and a hardcoded "0.35" would be the wrong separator in German too.
    getTariff.mockResolvedValue(tariff({ pricePerKwh: null, standingChargeMonth: 0, configured: false }))

    render(<SettingsPage />)
    await waitFor(() => expect(getTariff).toHaveBeenCalled())

    expect(price().placeholder).toBe('settings.pricePlaceholder')
    expect(standing().placeholder).toBe('settings.standingPlaceholder')
    expect(price().value).toBe('')
  })

  it('keeps a configured zero price visible', async () => {
    getTariff.mockResolvedValue(tariff({ pricePerKwh: 0 }))

    render(<SettingsPage />)

    await waitFor(() => expect(price().value).toBe('0'))
  })

  it('sends what was typed and confirms the save', async () => {
    getTariff.mockResolvedValue(tariff({ pricePerKwh: null, standingChargeMonth: 0, configured: false }))
    updateTariff.mockResolvedValue(tariff({ pricePerKwh: 0.42, standingChargeMonth: 9.5 }))

    render(<SettingsPage />)
    await waitFor(() => expect(getTariff).toHaveBeenCalled())

    await userEvent.type(price(), '0.42')
    await userEvent.type(standing(), '9.5')
    await userEvent.selectOptions(currency(), 'CHF')
    await userEvent.click(screen.getByText('common.save'))

    expect(updateTariff).toHaveBeenCalledWith({
      pricePerKwh: '0.42',
      standingChargeMonth: '9.5',
      currency: 'CHF',
    })
    expect(await screen.findByText('settings.saved')).toBeTruthy()
    // The unconfigured note goes away because the response says so.
    expect(screen.queryByText('settings.notConfigured')).toBeNull()
  })

  it('surfaces the server message when the tariff is rejected', async () => {
    getTariff.mockResolvedValue(tariff())
    updateTariff.mockRejectedValue(new Error('Price per kWh must be between 0 and 10.'))

    render(<SettingsPage />)
    await waitFor(() => expect(price().value).toBe('0.35'))

    await userEvent.click(screen.getByText('common.save'))

    expect(await screen.findByText('Price per kWh must be between 0 and 10.')).toBeTruthy()
    expect(screen.queryByText('settings.saved')).toBeNull()
  })

  it('clears a previous error on the next attempt', async () => {
    getTariff.mockResolvedValue(tariff())
    updateTariff.mockRejectedValueOnce(new Error('nope')).mockResolvedValueOnce(tariff())

    render(<SettingsPage />)
    await waitFor(() => expect(price().value).toBe('0.35'))

    await userEvent.click(screen.getByText('common.save'))
    expect(await screen.findByText('nope')).toBeTruthy()

    await userEvent.click(screen.getByText('common.save'))
    expect(await screen.findByText('settings.saved')).toBeTruthy()
    expect(screen.queryByText('nope')).toBeNull()
  })

  it('previews 100 kWh in the chosen currency as the price is typed', async () => {
    getTariff.mockResolvedValue(tariff({ pricePerKwh: null, standingChargeMonth: 0, configured: false }))

    render(<SettingsPage />)
    await waitFor(() => expect(getTariff).toHaveBeenCalled())
    expect(screen.queryByText(/settings.example/)).toBeNull()

    await userEvent.type(price(), '0.35')

    expect(await screen.findByText('settings.example €35.00')).toBeTruthy()
  })

  it('previews a price typed with a decimal comma', async () => {
    // The German hint asks for "0,35" in so many words, so the preview has to
    // read it — a blank preview reads as "this price is not valid".
    getTariff.mockResolvedValue(tariff({ pricePerKwh: null, standingChargeMonth: 0, configured: false }))

    render(<SettingsPage />)
    await waitFor(() => expect(getTariff).toHaveBeenCalled())

    await userEvent.type(price(), '0,35')

    expect(await screen.findByText('settings.example €35.00')).toBeTruthy()
  })

  it('says the tariff was cleared rather than saved when the price came back null', async () => {
    // An empty price is a valid instruction to delete the tariff, so a bare
    // "Saved" is how one gets wiped without anyone noticing.
    getTariff.mockResolvedValue(tariff())
    updateTariff.mockResolvedValue(tariff({ pricePerKwh: null, configured: false }))

    render(<SettingsPage />)
    await waitFor(() => expect(price().value).toBe('0.35'))

    await userEvent.clear(price())
    await userEvent.click(screen.getByText('common.save'))

    expect(await screen.findByText('settings.cleared')).toBeTruthy()
    expect(screen.queryByText('settings.saved')).toBeNull()
  })

  it('shows no preview for a price that is not a number', async () => {
    getTariff.mockResolvedValue(tariff({ pricePerKwh: null, standingChargeMonth: 0, configured: false }))

    render(<SettingsPage />)
    await waitFor(() => expect(getTariff).toHaveBeenCalled())

    await userEvent.type(price(), 'abc')

    expect(screen.queryByText(/settings.example/)).toBeNull()
  })

  it('still renders an editable form when the tariff cannot be loaded', async () => {
    getTariff.mockRejectedValue(new Error('boom'))

    render(<SettingsPage />)

    await waitFor(() => expect(getTariff).toHaveBeenCalled())
    expect(price().value).toBe('')
    expect(screen.getByText('common.save')).toBeTruthy()
  })
})
