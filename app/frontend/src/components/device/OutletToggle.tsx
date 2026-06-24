import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { turnOn, turnOff } from '../../api/devices'
import { ConfirmDialog } from '../ui/ConfirmDialog'

interface OutletToggleProps {
  ain: string
  currentState: string
  name?: string
  confirmOn?: boolean
  confirmOff?: boolean
  onToggled?: () => void
}

export function OutletToggle({ ain, currentState, name, confirmOn, confirmOff, onToggled }: OutletToggleProps) {
  const { t } = useTranslation()
  const [loading, setLoading] = useState(false)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const isOn = currentState === 'on'
  // Turning OFF when currently on → guarded by confirmOff; turning ON → guarded by confirmOn.
  const needsConfirm = isOn ? !!confirmOff : !!confirmOn

  const performToggle = async () => {
    if (loading) return
    setLoading(true)
    try {
      await (isOn ? turnOff(ain) : turnOn(ain))
      onToggled?.()
    } finally {
      setLoading(false)
    }
  }

  const handleClick = () => {
    if (loading) return
    if (needsConfirm) {
      setConfirmOpen(true)
      return
    }
    void performToggle()
  }

  const confirmAndToggle = () => {
    setConfirmOpen(false)
    void performToggle()
  }

  return (
    <>
      <button
        type="button"
        className={`toggle-switch${isOn ? ' toggle-switch--on' : ''}${loading ? ' toggle-switch--disabled' : ''}`}
        onClick={handleClick}
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

      {needsConfirm && (
        <ConfirmDialog
          open={confirmOpen}
          title={t('outlet.confirmTitle')}
          message={(isOn ? t('outlet.confirmTurnOff', { name }) : t('outlet.confirmTurnOn', { name }))
            + ' ' + t('outlet.protectedHint')}
          confirmLabel={isOn ? t('outlet.turnOff') : t('outlet.turnOn')}
          confirmVariant={isOn ? 'danger' : 'primary'}
          onConfirm={confirmAndToggle}
          onCancel={() => setConfirmOpen(false)}
        />
      )}
    </>
  )
}
