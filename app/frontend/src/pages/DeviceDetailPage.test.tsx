import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { DeviceDetailPage } from './DeviceDetailPage'
import type { Device } from '../api/devices'

const getDevice = vi.fn()

vi.mock('../api/devices', () => ({
  getDevice: (...a: unknown[]) => getDevice(...a),
  getDeviceXml: vi.fn(),
  setDeviceProtection: vi.fn(),
  setSetpoint: vi.fn(),
  turnOn: vi.fn(),
  turnOff: vi.fn(),
}))

vi.mock('../api/stats', () => ({
  getStats: () => Promise.resolve({ data: [] }),
}))

const getStandby = vi.fn()

vi.mock('../api/energy', () => ({
  getStandby: (...a: unknown[]) => getStandby(...a),
}))

vi.mock('../contexts/AuthContext', () => ({
  useAuth: () => ({ isAdmin: false }),
}))

vi.mock('react-router-dom', () => ({
  useParams: () => ({ ain: 'a1' }),
  Link: ({ children }: { children: React.ReactNode }) => <span>{children}</span>,
}))

// Charts pull ECharts into jsdom; none of them matter here.
vi.mock('../components/charts/TemperatureChart', () => ({ TemperatureChart: () => null }))
vi.mock('../components/charts/PowerChart', () => ({ PowerChart: () => null }))
vi.mock('../components/charts/EnergyChart', () => ({ EnergyChart: () => null }))
vi.mock('../components/charts/VoltageChart', () => ({ VoltageChart: () => null }))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, vars?: Record<string, unknown>) => (vars ? `${k} ${Object.values(vars).join(' ')}` : k),
    i18n: { language: 'en' },
  }),
}))

function thermostatDevice(overrides: Partial<NonNullable<Device['thermostat']>> = {}): Device {
  return {
    ain: 'a1',
    name: 'Living room',
    present: true,
    features: { outlet: false, thermostat: true, powerMeter: false, temperatureSensor: false },
    thermostat: {
      setpoint: 21.5,
      comfort: 22,
      saving: 16,
      mode: 'temperature',
      battery: 65,
      batteryLow: false,
      windowOpen: false,
      boostActive: false,
      holidayActive: false,
      summerActive: false,
      errorCode: 0,
      ...overrides,
    },
  } as Device
}

function powerMeterDevice(): Device {
  return {
    ain: 'a1',
    name: 'iMac outlet',
    present: true,
    features: { outlet: true, thermostat: false, powerMeter: true, temperatureSensor: false },
    powerMeter: { voltage: 232.1, power: 122.7, energy: 8 },
    outlet: { state: 'on', mode: 'manual', lock: false, deviceLock: false },
  } as Device
}

describe('DeviceDetailPage thermostat card', () => {
  beforeEach(() => {
    getDevice.mockReset()
    getStandby.mockReset()
    getStandby.mockResolvedValue(null)
  })

  it('renders the thermostat card when the API sends thermostat state', async () => {
    // Regression: the API used to hard-code thermostat: null, so this card
    // could never render at all despite the feature flag being set.
    getDevice.mockResolvedValue(thermostatDevice())

    render(<DeviceDetailPage />)

    expect(await screen.findByText('detail.thermostat')).toBeTruthy()
    expect(screen.getByText('detail.setpoint')).toBeTruthy()
    expect(screen.getByText('22 °C')).toBeTruthy()
    expect(screen.getByText('16 °C')).toBeTruthy()
    expect(screen.getByText('65 %')).toBeTruthy()
  })

  it('hides the card when the device reports no thermostat state', async () => {
    // The cached-device path sends thermostat: null by design.
    getDevice.mockResolvedValue({
      ain: 'a1',
      name: 'Living room',
      present: true,
      features: { outlet: false, thermostat: true, powerMeter: false, temperatureSensor: false },
      thermostat: undefined,
    } as Device)

    render(<DeviceDetailPage />)

    await waitFor(() => expect(getDevice).toHaveBeenCalled())
    expect(screen.queryByText('detail.thermostat')).toBeNull()
  })

  it('shows a valve badge instead of a temperature when the valve is off', async () => {
    getDevice.mockResolvedValue(thermostatDevice({ mode: 'off', setpoint: null }))

    render(<DeviceDetailPage />)

    expect(await screen.findByText('detail.valveOff')).toBeTruthy()
    // The slider stays available so the valve can be brought back under control.
    expect(screen.getByText('detail.setpoint')).toBeTruthy()
  })

  it('shows the fully-open badge for max mode', async () => {
    getDevice.mockResolvedValue(thermostatDevice({ mode: 'max', setpoint: null }))

    render(<DeviceDetailPage />)

    expect(await screen.findByText('detail.valveMax')).toBeTruthy()
  })

  it('surfaces only the active status flags', async () => {
    getDevice.mockResolvedValue(thermostatDevice({ windowOpen: true, boostActive: true }))

    render(<DeviceDetailPage />)

    expect(await screen.findByText('detail.windowOpen')).toBeTruthy()
    expect(screen.getByText('detail.boostActive')).toBeTruthy()
    expect(screen.queryByText('detail.holidayActive')).toBeNull()
    expect(screen.queryByText('detail.summerActive')).toBeNull()
  })

  it('omits the status row entirely when nothing is active', async () => {
    getDevice.mockResolvedValue(thermostatDevice())

    render(<DeviceDetailPage />)

    await screen.findByText('detail.thermostat')
    expect(screen.queryByText('detail.thermostatStatus')).toBeNull()
  })

  it('falls back to the flag-only battery display on older firmware', async () => {
    getDevice.mockResolvedValue(thermostatDevice({ battery: null, batteryLow: true }))

    render(<DeviceDetailPage />)

    expect(await screen.findByText('detail.batteryLow')).toBeTruthy()
  })

  it('translates a known HKR error code and hides code 0', async () => {
    getDevice.mockResolvedValue(thermostatDevice({ errorCode: 3 }))

    render(<DeviceDetailPage />)

    expect(await screen.findByText('detail.hkrError3')).toBeTruthy()
  })

  it('falls back to the bare code for an unknown error', async () => {
    getDevice.mockResolvedValue(thermostatDevice({ errorCode: 9 }))

    render(<DeviceDetailPage />)

    expect(await screen.findByText('detail.hkrErrorUnknown 9')).toBeTruthy()
  })
})

describe('DeviceDetailPage standby', () => {
  beforeEach(() => {
    getDevice.mockReset()
    getStandby.mockReset()
    getDevice.mockResolvedValue(powerMeterDevice())
  })

  it('shows the idle floor and its annual cost inside the power meter card', async () => {
    getStandby.mockResolvedValue({
      watts: 40.48, annualKwh: 354.6, annualCost: 124.11,
      currency: 'EUR', dutyCyclePercent: 100, idleWatts: 40.48,
      samples: 111, windowDays: 7, source: 'rollup',
    })

    render(<DeviceDetailPage />)

    expect(await screen.findByText('energy.standby')).toBeTruthy()
    expect(screen.getByText('40.5 W')).toBeTruthy()
    expect(screen.getByText('€124.11')).toBeTruthy()
    // Always says what the figure is based on.
    expect(screen.getByText('energy.standbyBasis 7 111')).toBeTruthy()
  })

  it('reports the duty cycle and the idle draw for a device that is mostly off', async () => {
    // The case a round-the-clock floor cannot describe: a printer that draws
    // nothing for 97% of the week, and a real 14 W whenever it is switched on.
    // Without this line the card says "0 W" and leaves that 14 W invisible.
    getStandby.mockResolvedValue({
      watts: 0, annualKwh: 0, annualCost: 0,
      currency: 'EUR', dutyCyclePercent: 3, idleWatts: 14.01,
      samples: 672, windowDays: 7, source: 'rollup',
    })

    render(<DeviceDetailPage />)

    // formatWatts drops to one decimal above 10 W, so 14.01 renders as "14 W".
    expect(await screen.findByText('energy.dutyPartial 3 14 W')).toBeTruthy()
  })

  it('says a device was simply off rather than implying it draws nothing', async () => {
    getStandby.mockResolvedValue({
      watts: 0, annualKwh: 0, annualCost: 0,
      currency: 'EUR', dutyCyclePercent: 0, idleWatts: null,
      samples: 672, windowDays: 7, source: 'rollup',
    })

    render(<DeviceDetailPage />)

    expect(await screen.findByText('energy.dutyOff')).toBeTruthy()
  })

  it('does not repeat the floor as an idle draw for an always-on device', async () => {
    getStandby.mockResolvedValue({
      watts: 40.48, annualKwh: 354.6, annualCost: 124.11,
      currency: 'EUR', dutyCyclePercent: 100, idleWatts: 40.48,
      samples: 672, windowDays: 7, source: 'rollup',
    })

    render(<DeviceDetailPage />)

    expect(await screen.findByText('energy.dutyAlways')).toBeTruthy()
    expect(screen.queryByText(/energy.dutyPartial/)).toBeNull()
  })

  it('reports the duty cycle even when the on-period was too short for an idle figure', async () => {
    getStandby.mockResolvedValue({
      watts: 0, annualKwh: 0, annualCost: 0,
      currency: 'EUR', dutyCyclePercent: 1.5, idleWatts: null,
      samples: 672, windowDays: 7, source: 'rollup',
    })

    render(<DeviceDetailPage />)

    expect(await screen.findByText('energy.dutyPartialUnknown 1.5')).toBeTruthy()
  })

  it('omits the row entirely when there is too little data to quote a floor', async () => {
    // The endpoint answers 204 → null. Showing "0 W" here would be a claim we
    // cannot make.
    getStandby.mockResolvedValue(null)

    render(<DeviceDetailPage />)

    expect(await screen.findByText('detail.powerMeter')).toBeTruthy()
    expect(screen.queryByText('energy.standby')).toBeNull()
    expect(screen.queryByText('0 W')).toBeNull()
  })

  it('shows the draw but no cost when no tariff is configured', async () => {
    getStandby.mockResolvedValue({
      watts: 40.48, annualKwh: 354.6, annualCost: null,
      currency: 'EUR', dutyCyclePercent: 100, idleWatts: 40.48,
      samples: 111, windowDays: 7, source: 'rollup',
    })

    render(<DeviceDetailPage />)

    expect(await screen.findByText('40.5 W')).toBeTruthy()
    expect(screen.queryByText('energy.standbyAnnual')).toBeNull()
  })

  it('still renders the card when the standby request fails', async () => {
    getStandby.mockRejectedValue(new Error('boom'))

    render(<DeviceDetailPage />)

    expect(await screen.findByText('detail.powerMeter')).toBeTruthy()
    expect(screen.queryByText('energy.standby')).toBeNull()
  })

  it('asks for the standby of the device on screen', async () => {
    getStandby.mockResolvedValue(null)

    render(<DeviceDetailPage />)

    await waitFor(() => expect(getStandby).toHaveBeenCalledWith('a1'))
  })
})
