import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { EnergyCostReadout } from './EnergyCostReadout'

const getEnergyCost = vi.fn()

vi.mock('../../api/energy', () => ({
  getEnergyCost: (...a: unknown[]) => getEnergyCost(...a),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, vars?: Record<string, unknown>) => (vars ? `${k} ${Object.values(vars).join(' ')}` : k),
    i18n: { language: 'en' },
  }),
}))

const coverage = (over: Record<string, unknown> = {}) => ({
  daysInRange: 7, daysWithData: 7, gapDays: 0, zeroDays: 0,
  firstDay: '2026-06-01', lastDay: '2026-06-07', estimatedTodayWh: 0, ...over,
})

const payload = (over: Record<string, unknown> = {}) => ({
  from: '2026-06-01T00:00:00+00:00',
  to: '2026-06-07T23:59:59+00:00',
  currency: 'EUR',
  pricePerKwh: 0.35,
  configured: true,
  spansCollectorChange: false,
  devices: [{ ain: 'a1', name: 'iMac', energyWh: 7000, cost: 2.45, coverage: coverage() }],
  household: { energyWh: 10000, energyCost: 3.5, standingCost: 4.6, cost: 8.1, coverage: coverage() },
  ...over,
})

describe('EnergyCostReadout', () => {
  // Block body, not `() => getEnergyCost.mockReset()`: mockReset returns the
  // mock itself, and Vitest treats a function returned from a hook as teardown —
  // it would then call the mock after every test.
  beforeEach(() => { getEnergyCost.mockReset() })

  it('requests exactly the window it was given', async () => {
    getEnergyCost.mockResolvedValue(payload())

    render(<EnergyCostReadout ain="a1" from="2026-06-01T00:00:00+00:00" to="2026-06-07T23:59:59+00:00" />)

    await waitFor(() => expect(getEnergyCost).toHaveBeenCalledWith(
      '2026-06-01T00:00:00+00:00',
      '2026-06-07T23:59:59+00:00',
    ))
  })

  it('shows energy, device cost and the household share', async () => {
    getEnergyCost.mockResolvedValue(payload())

    render(<EnergyCostReadout ain="a1" from="f" to="t" />)

    expect(await screen.findByText('7 kWh')).toBeTruthy()
    expect(screen.getByText('€2.45')).toBeTruthy()
    // 7000 of 10000 Wh
    expect(screen.getByText('energy.sharePercent 70')).toBeTruthy()
  })

  it('always states what the total is based on', async () => {
    getEnergyCost.mockResolvedValue(payload())

    render(<EnergyCostReadout ain="a1" from="f" to="t" />)

    expect(await screen.findByText('energy.coverageNote 7 7')).toBeTruthy()
  })

  it('warns only when days are genuinely missing', async () => {
    getEnergyCost.mockResolvedValue(payload({
      devices: [{ ain: 'a1', name: 'iMac', energyWh: 7000, cost: 2.45, coverage: coverage({ daysWithData: 5, gapDays: 2 }) }],
    }))

    render(<EnergyCostReadout ain="a1" from="f" to="t" />)

    expect(await screen.findByText('energy.gapWarning 2')).toBeTruthy()
  })

  it('does not warn for a device that simply started mid-range', async () => {
    // Fewer days than the range spans, but nothing lost — warning here would
    // fire on every newly added device.
    getEnergyCost.mockResolvedValue(payload({
      devices: [{ ain: 'a1', name: 'iMac', energyWh: 7000, cost: 2.45, coverage: coverage({ daysWithData: 3, gapDays: 0 }) }],
    }))

    render(<EnergyCostReadout ain="a1" from="f" to="t" />)

    await waitFor(() => expect(getEnergyCost).toHaveBeenCalled())
    expect(screen.queryByText(/energy.gapWarning/)).toBeNull()
  })

  it('never shows a cost without a tariff', async () => {
    getEnergyCost.mockResolvedValue(payload({
      configured: false,
      pricePerKwh: null,
      devices: [{ ain: 'a1', name: 'iMac', energyWh: 7000, cost: null, coverage: coverage() }],
      household: { energyWh: 10000, energyCost: null, standingCost: null, cost: null, coverage: coverage() },
    }))

    render(<EnergyCostReadout ain="a1" from="f" to="t" />)

    expect(await screen.findByText('energy.noTariff')).toBeTruthy()
    expect(screen.queryByText(/0\.00/)).toBeNull()
    // Energy is still reported — only the money is unknown.
    expect(screen.getByText('7 kWh')).toBeTruthy()
  })

  it('shows the day-shift note only for a window that straddles the change', async () => {
    getEnergyCost.mockResolvedValue(payload({ spansCollectorChange: true }))

    render(<EnergyCostReadout ain="a1" from="f" to="t" />)

    expect(await screen.findByText('energy.dayShiftNote')).toBeTruthy()
  })

  it('hides the day-shift note otherwise', async () => {
    getEnergyCost.mockResolvedValue(payload())

    render(<EnergyCostReadout ain="a1" from="f" to="t" />)

    await waitFor(() => expect(getEnergyCost).toHaveBeenCalled())
    expect(screen.queryByText('energy.dayShiftNote')).toBeNull()
  })

  it('says so when the device has no readings in the window', async () => {
    getEnergyCost.mockResolvedValue(payload({ devices: [] }))

    render(<EnergyCostReadout ain="a1" from="f" to="t" />)

    expect(await screen.findByText('energy.noData')).toBeTruthy()
  })

  it('renders nothing when the request fails', async () => {
    getEnergyCost.mockRejectedValue(new Error('boom'))

    const { container } = render(<EnergyCostReadout ain="a1" from="f" to="t" />)

    await waitFor(() => expect(getEnergyCost).toHaveBeenCalled())
    expect(container.textContent).toBe('')
  })
})
