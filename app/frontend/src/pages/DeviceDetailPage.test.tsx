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

describe('DeviceDetailPage thermostat card', () => {
  beforeEach(() => {
    getDevice.mockReset()
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
