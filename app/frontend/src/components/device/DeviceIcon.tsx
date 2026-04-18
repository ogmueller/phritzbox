interface DeviceIconProps {
  functionBitMask?: number
  size?: number
  className?: string
}

function OutletIcon({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 20 20" fill="currentColor">
      <path d="M10 1a7 7 0 0 0-7 7c0 3.3 2.3 6 5.5 6.7V18a1.5 1.5 0 0 0 3 0v-3.3C14.7 14 17 11.3 17 8a7 7 0 0 0-7-7zM8 6a1 1 0 0 1 1 1v2a1 1 0 0 1-2 0V7a1 1 0 0 1 1-1zm4 0a1 1 0 0 1 1 1v2a1 1 0 0 1-2 0V7a1 1 0 0 1 1-1z"/>
    </svg>
  )
}

function ThermostatIcon({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 20 20" fill="currentColor">
      <path d="M10 1a3 3 0 0 0-3 3v7.26A4.5 4.5 0 1 0 13 11.26V4a3 3 0 0 0-3-3zm0 2a1 1 0 0 1 1 1v6h2a2.5 2.5 0 1 1-4.74 1.12.75.75 0 0 1 .74-.62V4a1 1 0 0 1 1-1z"/>
      <circle cx="14.5" cy="5" r="1.2"/>
      <rect x="13.5" y="7" width="2" height="0.8" rx="0.4"/>
      <rect x="13.5" y="9" width="2" height="0.8" rx="0.4"/>
    </svg>
  )
}

function TemperatureIcon({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 20 20" fill="currentColor">
      <path d="M10 1a3 3 0 0 0-3 3v7.26A4.5 4.5 0 1 0 13 11.26V4a3 3 0 0 0-3-3zm0 2a1 1 0 0 1 1 1v7.5a.75.75 0 0 1 .5.7 1.5 1.5 0 1 1-3 0 .75.75 0 0 1 .5-.7V4a1 1 0 0 1 1-1z"/>
    </svg>
  )
}

function PowerMeterIcon({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 20 20" fill="currentColor">
      <path d="M11.5 1L6 10h4l-1.5 9L14 10h-4L11.5 1z"/>
    </svg>
  )
}

function DectRepeaterIcon({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
      <path d="M10 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z" fill="currentColor" stroke="none"/>
      <path d="M5.5 12.5a6 6 0 0 1 9 0"/>
      <path d="M3 9.5a10 10 0 0 1 14 0"/>
    </svg>
  )
}

function GenericDeviceIcon({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 20 20" fill="currentColor">
      <rect x="3" y="4" width="14" height="12" rx="2"/>
      <circle cx="7" cy="10" r="1.5" fill="white"/>
      <circle cx="13" cy="10" r="1.5" fill="white"/>
    </svg>
  )
}

const BIT_OUTLET = 1 << 9       // 512
const BIT_THERMOSTAT = 1 << 6   // 64
const BIT_TEMP_SENSOR = 1 << 8  // 256
const BIT_POWER_METER = 1 << 7  // 128
const BIT_DECT_REPEATER = 1 << 10 // 1024

export function DeviceIcon({ functionBitMask, size = 20, className }: DeviceIconProps) {
  const mask = functionBitMask ?? 0

  let icon: JSX.Element
  if (mask & BIT_OUTLET) {
    icon = <OutletIcon size={size} />
  } else if (mask & BIT_THERMOSTAT) {
    icon = <ThermostatIcon size={size} />
  } else if (mask & BIT_TEMP_SENSOR) {
    icon = <TemperatureIcon size={size} />
  } else if (mask & BIT_POWER_METER) {
    icon = <PowerMeterIcon size={size} />
  } else if (mask & BIT_DECT_REPEATER) {
    icon = <DectRepeaterIcon size={size} />
  } else {
    icon = <GenericDeviceIcon size={size} />
  }

  return <span className={`device-icon${className ? ` ${className}` : ''}`}>{icon}</span>
}
