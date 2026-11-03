import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ComfortZone, ComfortZonePayload, getComfortZone, updateComfortZone } from '../../api/comfortZone'
import { Card } from '../ui/Card'
import { Button } from '../ui/Button'
import { TextInput } from '../ui/TextInput'

const formFor = (zone: ComfortZone): ComfortZonePayload => ({
  coldBelow: String(zone.cold),
  warmFrom: String(zone.warm),
})

/**
 * The household comfort zone that colours temperature charts. Saved on its own,
 * so a mistake here can never take the tariff form down with it.
 */
export function ComfortZoneCard() {
  const { t } = useTranslation()
  const [form, setForm] = useState<ComfortZonePayload>({ coldBelow: '', warmFrom: '' })
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const [saving, setSaving] = useState(false)

  // Same guard as the tariff form: a load that answers after an edit or a save
  // must not overwrite what the operator is looking at.
  const local = useRef(false)

  const edit = (patch: Partial<ComfortZonePayload>) => {
    local.current = true
    setSaved(false)
    setForm((f) => ({ ...f, ...patch }))
  }

  useEffect(() => {
    let active = true
    getComfortZone()
      .then((zone) => {
        if (!active || local.current) return
        setForm(formFor(zone))
      })
      .catch(() => {})
    return () => { active = false }
  }, [])

  const save = async () => {
    setSaving(true)
    setError(null)
    setSaved(false)
    try {
      const zone = await updateComfortZone(form)
      setForm(formFor(zone))
      local.current = true
      setSaved(true)
    } catch (e) {
      setError(e instanceof Error ? e.message : t('settings.comfortSaveFailed'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card title={t('settings.comfortTitle')}>
      <p className="cost-note">{t('settings.comfortIntro')}</p>

      {error && <div className="alert alert--danger">{error}</div>}
      {saved && <div className="alert alert--success">{t('settings.comfortSaved')}</div>}

      <TextInput
        label={t('settings.comfortColdBelow')}
        id="comfort-cold"
        value={form.coldBelow}
        onChange={(v) => edit({ coldBelow: v })}
      />
      <TextInput
        label={t('settings.comfortWarmFrom')}
        id="comfort-warm"
        value={form.warmFrom}
        onChange={(v) => edit({ warmFrom: v })}
      />
      <div className="stat-tile-hint">{t('settings.comfortHint')}</div>

      <div className="table-footer">
        <Button onClick={save} disabled={saving}>
          {saving ? t('common.saving') : t('common.save')}
        </Button>
      </div>
    </Card>
  )
}
