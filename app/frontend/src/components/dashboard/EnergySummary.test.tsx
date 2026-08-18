import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { EnergySummary } from './EnergySummary'

const getEnergySummary = vi.fn()
let devices: unknown[] = []
let isAdmin = false

vi.mock('../../api/energy', () => ({
  getEnergySummary: () => getEnergySummary(),
}))

vi.mock('../../contexts/DeviceContext', () => ({
  useDeviceContext: () => ({ devices, loading: false, error: null, refresh: vi.fn() }),
}))

vi.mock('../../contexts/AuthContext', () => ({
  useAuth: () => ({ isAdmin }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ children }: { children: React.ReactNode }) => <a href="#">{children}</a>,
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, vars?: Record<string, unknown>) => (vars ? `${k} ${Object.values(vars).join(' ')}` : k),
    i18n: { language: 'en' },
  }),
}))

const summary = (over: Record<string, unknown> = {}) => ({
  currency: 'EUR',
  configured: true,
  today: { energyWh: 1500, cost: 0.53, estimated: false },
  monthToDate: { energyWh: 18864, energyCost: 6.6, standingCost: 12, cost: 18.6, gapDays: 0 },
  topConsumer: { ain: 'a1', name: 'iMac', energyWh: 10772, cost: 3.77 },
  standby: { watts: 40.48, annualKwh: 354.6, annualCost: 124.11, devices: 1 },
  ...over,
})

describe('EnergySummary', () => {
  beforeEach(() => {
    getEnergySummary.mockReset()
    devices = []
    isAdmin = false
  })

  it('sums live wattage from the device list without an extra request', async () => {
    devices = [
      { ain: 'a1', powerMeter: { power: 122.7, voltage: 232, energy: 1 } },
      { ain: 'a2', powerMeter: { power: 7.3, voltage: 232, energy: 1 } },
    ]
    getEnergySummary.mockResolvedValue(summary())

    render(<EnergySummary />)

    await waitFor(() => expect(getEnergySummary).toHaveBeenCalledTimes(1))
    expect(screen.getByText('130 W')).toBeTruthy()
  })

  it('shows an em dash rather than 0 W when no device reports power', async () => {
    // The cached-device fallback sets powerMeter to null; "0 W" would be a claim
    // we cannot make.
    devices = [{ ain: 'a1', powerMeter: null }]
    getEnergySummary.mockResolvedValue(summary())

    render(<EnergySummary />)

    await waitFor(() => expect(getEnergySummary).toHaveBeenCalled())
    expect(screen.getByText('—')).toBeTruthy()
  })

  it('never renders a cost when no tariff is configured', async () => {
    getEnergySummary.mockResolvedValue(summary({
      configured: false,
      monthToDate: { energyWh: 18864, energyCost: null, standingCost: null, cost: null, gapDays: 0 },
      standby: { watts: 40.48, annualKwh: 354.6, annualCost: null, devices: 1 },
    }))

    render(<EnergySummary />)

    await waitFor(() => expect(getEnergySummary).toHaveBeenCalled())
    expect(screen.queryByText(/0\.00/)).toBeNull()
    expect(screen.queryByText(/€/)).toBeNull()
  })

  it('offers admins a link to set the tariff', async () => {
    isAdmin = true
    getEnergySummary.mockResolvedValue(summary({ configured: false }))

    render(<EnergySummary />)

    expect(await screen.findByText('energy.configureTariff')).toBeTruthy()
  })

  it('does not offer the link to non-admins', async () => {
    isAdmin = false
    getEnergySummary.mockResolvedValue(summary({ configured: false }))

    render(<EnergySummary />)

    await waitFor(() => expect(getEnergySummary).toHaveBeenCalled())
    expect(screen.queryByText('energy.configureTariff')).toBeNull()
  })

  it('leads with the month energy when there is no cost to show', async () => {
    // The figure slot must hold a figure. Leaving it to the link left the tile
    // saying nothing at all about a month it has 18.86 kWh of data for.
    isAdmin = true
    getEnergySummary.mockResolvedValue(summary({
      configured: false,
      monthToDate: { energyWh: 18864, energyCost: null, standingCost: null, cost: null, gapDays: 0 },
    }))

    render(<EnergySummary />)

    expect(await screen.findByText('18.86 kWh')).toBeTruthy()
  })

  it('does not prompt for a tariff before the answer is in', async () => {
    // `configured` is false while the request is in flight, so prompting on it
    // flashes "set a tariff" at operators who have already set one.
    isAdmin = true
    let answer: ((s: unknown) => void) | undefined
    getEnergySummary.mockImplementation(() => new Promise((resolve) => { answer = resolve }))

    render(<EnergySummary />)

    await waitFor(() => expect(getEnergySummary).toHaveBeenCalled())
    expect(screen.queryByText('energy.configureTariff')).toBeNull()

    answer?.(summary({ configured: false }))
    expect(await screen.findByText('energy.configureTariff')).toBeTruthy()
  })

  it('says the month total includes the standing charge', async () => {
    // 18.86 kWh at 0.35 is 6.60, but the tile says 18.60 — the difference is the
    // standing charge, and unexplained it reads as a wrong number.
    getEnergySummary.mockResolvedValue(summary())

    render(<EnergySummary />)

    expect(await screen.findByText('energy.includesStanding 18.86 kWh €12.00')).toBeTruthy()
  })

  it('shows the plain energy when no standing charge is billed', async () => {
    getEnergySummary.mockResolvedValue(summary({
      monthToDate: { energyWh: 18864, energyCost: 6.6, standingCost: 0, cost: 6.6, gapDays: 0 },
    }))

    render(<EnergySummary />)

    expect(await screen.findByText('18.86 kWh')).toBeTruthy()
    expect(screen.queryByText(/energy.includesStanding/)).toBeNull()
  })

  it('explains the warn border when days are missing', async () => {
    getEnergySummary.mockResolvedValue(summary({
      monthToDate: { energyWh: 18864, energyCost: 6.6, standingCost: 12, cost: 18.6, gapDays: 3 },
    }))

    render(<EnergySummary />)

    expect(await screen.findByText('energy.gapWarning 3')).toBeTruthy()
  })

  it('keeps the gap explanation when there is no tariff either', async () => {
    isAdmin = true
    getEnergySummary.mockResolvedValue(summary({
      configured: false,
      monthToDate: { energyWh: 18864, energyCost: null, standingCost: null, cost: null, gapDays: 3 },
    }))

    render(<EnergySummary />)

    expect(await screen.findByText('energy.gapWarning 3')).toBeTruthy()
    expect(screen.getByText('energy.configureTariff')).toBeTruthy()
  })

  it('marks today as estimated only while it is integrated from power', async () => {
    getEnergySummary.mockResolvedValue(summary({
      today: { energyWh: 1500, cost: 0.53, estimated: true },
    }))

    render(<EnergySummary />)

    expect(await screen.findByText('energy.estimated')).toBeTruthy()
  })

  it('does not mark today estimated once the device has reported', async () => {
    getEnergySummary.mockResolvedValue(summary())

    render(<EnergySummary />)

    await waitFor(() => expect(getEnergySummary).toHaveBeenCalled())
    expect(screen.queryByText('energy.estimated')).toBeNull()
  })

  it('survives the summary request failing', async () => {
    // The device table below must not disappear because a cost figure could not
    // be produced.
    devices = [{ ain: 'a1', powerMeter: { power: 10, voltage: 232, energy: 1 } }]
    getEnergySummary.mockRejectedValue(new Error('boom'))

    render(<EnergySummary />)

    await waitFor(() => expect(getEnergySummary).toHaveBeenCalled())
    expect(screen.getByText('energy.liveNow')).toBeTruthy()
    expect(screen.getByText('10 W')).toBeTruthy()
  })
})
