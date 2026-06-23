import { useState } from 'react'

interface SwitchProps {
  checked: boolean
  onChange: (next: boolean) => void | Promise<void>
  disabled?: boolean
  labelOn?: string
  labelOff?: string
  ariaLabel?: string
}

/**
 * Reusable on/off toggle styled like the Fritz!Box outlet switch.
 * Disables itself while an async onChange is in flight.
 */
export function Switch({ checked, onChange, disabled, labelOn, labelOff, ariaLabel }: SwitchProps) {
  const [busy, setBusy] = useState(false)
  const isDisabled = disabled || busy

  const handle = async () => {
    if (isDisabled) return
    setBusy(true)
    try {
      await onChange(!checked)
    } finally {
      setBusy(false)
    }
  }

  const label = checked ? labelOn : labelOff

  return (
    <button
      type="button"
      className={`toggle-switch${checked ? ' toggle-switch--on' : ''}${isDisabled ? ' toggle-switch--disabled' : ''}`}
      onClick={handle}
      disabled={isDisabled}
      role="switch"
      aria-checked={checked}
      aria-label={ariaLabel}
    >
      {label !== undefined && <span className="toggle-switch__label">{label}</span>}
      <span className="toggle-switch__track">
        <span className="toggle-switch__thumb" />
      </span>
    </button>
  )
}
