import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { setSetpoint } from '../../api/devices'
import { Button } from '../ui/Button'

interface SetpointControlProps {
  ain: string
  currentSetpoint: number | null
  onChanged?: () => void
}

export function SetpointControl({ ain, currentSetpoint, onChanged }: SetpointControlProps) {
  const { t } = useTranslation()
  const [value, setValue] = useState(currentSetpoint ?? 20)
  const [loading, setLoading] = useState(false)

  const apply = async () => {
    setLoading(true)
    try {
      await setSetpoint(ain, value)
      onChanged?.()
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="setpoint-control">
      <input
        type="range"
        min={8}
        max={28}
        step={0.5}
        value={value}
        onChange={(e) => setValue(Number(e.target.value))}
        className="setpoint-slider"
      />
      <span className="setpoint-value">{value} °C</span>
      <Button size="sm" onClick={apply} disabled={loading}>
        {loading ? '…' : t('common.set')}
      </Button>
    </div>
  )
}
