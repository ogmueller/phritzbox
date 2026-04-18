import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { turnOn, turnOff } from '../../api/devices'

interface OutletToggleProps {
  ain: string
  currentState: string
  onToggled?: () => void
}

export function OutletToggle({ ain, currentState, onToggled }: OutletToggleProps) {
  const { t } = useTranslation()
  const [loading, setLoading] = useState(false)
  const isOn = currentState === 'on'

  const toggle = async () => {
    if (loading) return
    setLoading(true)
    try {
      await (isOn ? turnOff(ain) : turnOn(ain))
      onToggled?.()
    } finally {
      setLoading(false)
    }
  }

  return (
    <button
      type="button"
      className={`toggle-switch${isOn ? ' toggle-switch--on' : ''}${loading ? ' toggle-switch--disabled' : ''}`}
      onClick={toggle}
      disabled={loading}
      aria-label={isOn ? t('outlet.turnOff') : t('outlet.turnOn')}
      role="switch"
      aria-checked={isOn}
    >
      <span className="toggle-switch__label">{isOn ? 'AN' : 'AUS'}</span>
      <span className="toggle-switch__track">
        <span className="toggle-switch__thumb" />
      </span>
    </button>
  )
}
