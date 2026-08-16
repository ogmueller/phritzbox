import { api } from './client'

export interface Device {
  ain: string
  name: string
  present: boolean
  manufacturer?: string
  productName?: string
  firmwareVersion?: string
  functionBitMask?: number
  productImage?: string | null
  source?: 'live' | 'cached'
  confirmOn?: boolean
  confirmOff?: boolean
  features: {
    outlet: boolean
    thermostat: boolean
    powerMeter: boolean
    temperatureSensor: boolean
  }
  outlet?: {
    state: string
    mode: string
    lock: string
  }
  thermostat?: {
    /** null when mode is 'off' or 'max' — the valve is not tracking a temperature. */
    setpoint: number | null
    comfort: number | null
    saving: number | null
    mode: 'off' | 'max' | 'temperature'
    /** Charge in percent. null on firmware that only reports batteryLow. */
    battery: number | null
    batteryLow: boolean | null
    windowOpen: boolean | null
    boostActive: boolean | null
    holidayActive: boolean | null
    summerActive: boolean | null
    /** 0 = no error; 1-6 are AVM's mounting/valve diagnostics. */
    errorCode: number | null
  }
  powerMeter?: {
    voltage: number
    power: number
    energy: number
  }
  temperature?: {
    celsius: number
    offset: number
  }
}

export function getDevices(): Promise<Device[]> {
  return api.get<Device[]>('/api/devices')
}

export function getDevice(ain: string): Promise<Device> {
  return api.get<Device>(`/api/devices/${encodeURIComponent(ain)}`)
}

export function turnOn(ain: string): Promise<void> {
  return api.post<void>(`/api/devices/${encodeURIComponent(ain)}/on`)
}

export function turnOff(ain: string): Promise<void> {
  return api.post<void>(`/api/devices/${encodeURIComponent(ain)}/off`)
}

export function setSetpoint(ain: string, celsius: number): Promise<void> {
  return api.put<void>(`/api/devices/${encodeURIComponent(ain)}/setpoint`, { celsius })
}

export function setDeviceProtection(ain: string, confirmOn: boolean, confirmOff: boolean): Promise<void> {
  return api.put<void>(`/api/devices/${encodeURIComponent(ain)}/protection`, { confirmOn, confirmOff })
}

export async function getDeviceXml(ain: string): Promise<string> {
  const res = await api.get<{ ain: string; xml: string }>(`/api/devices/${encodeURIComponent(ain)}/xml`)
  return res.xml
}
