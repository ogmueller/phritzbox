import { useState, useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { getTariff, updateTariff, Tariff, TariffPayload } from '../api/settings'
import { Card } from '../components/ui/Card'
import { Button } from '../components/ui/Button'
import { TextInput } from '../components/ui/TextInput'
import { SelectField } from '../components/ui/SelectField'
import { formatCurrency, parseDecimal } from '../format'

/** Mirrors TariffSettings::CURRENCIES — the server rejects anything else. */
const CURRENCIES = ['EUR', 'CHF', 'GBP', 'USD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK']

const EMPTY: TariffPayload = { pricePerKwh: '', standingChargeMonth: '', currency: 'EUR' }

export function SettingsPage() {
  const { t, i18n } = useTranslation()
  const [form, setForm] = useState<TariffPayload>(EMPTY)
  const [configured, setConfigured] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const [saving, setSaving] = useState(false)

  const formFor = (tariff: Tariff): TariffPayload => ({
    // An unconfigured price is blank, not "0" — the two mean different things.
    pricePerKwh: tariff.pricePerKwh === null ? '' : String(tariff.pricePerKwh),
    standingChargeMonth: tariff.standingChargeMonth === 0 ? '' : String(tariff.standingChargeMonth),
    currency: tariff.currency,
  })

  /**
   * True once the form holds something newer than the load that is in flight —
   * an edit the operator has made, or the answer to a save.
   *
   * A ref, not state: the load callback has to read the value at the moment it
   * answers, not the value captured when the effect ran.
   *
   * Both cases matter. StrictMode puts two loads in flight and either may answer
   * late; because a blank price means "clear the tariff", a late answer that
   * wipes the field turns the next Save into a silent delete that still reports
   * success. A load that predates a save is just as stale as one that predates
   * an edit — it carries the tariff as it was *before* the save, so letting it
   * through blanks a price the operator has just watched the server accept.
   */
  const local = useRef(false)

  const edit = (patch: Partial<TariffPayload>) => {
    local.current = true
    setForm((f) => ({ ...f, ...patch }))
  }

  useEffect(() => {
    let active = true
    getTariff()
      .then((tariff) => {
        if (!active || local.current) return
        setForm(formFor(tariff))
        setConfigured(tariff.configured)
      })
      .catch(() => {})

    return () => { active = false }
  }, [])

  const save = async () => {
    setSaving(true)
    setError(null)
    setSaved(false)
    try {
      const tariff = await updateTariff(form)
      // Show what was actually stored, not what was typed. If the two ever
      // disagree the operator sees it here, instead of discovering it later on
      // the dashboard.
      setForm(formFor(tariff))
      local.current = true
      setConfigured(tariff.configured)
      setSaved(true)
    } catch (e) {
      setError(e instanceof Error ? e.message : t('settings.saveFailed'))
    } finally {
      setSaving(false)
    }
  }

  // A live example, so the currency and locale are visibly in effect before
  // saving — and, since it disappears for anything unparseable, a check that the
  // price will be read as a number at all. Read through parseDecimal so a comma
  // counts here exactly as it does at the endpoint.
  const priceNumber = parseDecimal(form.pricePerKwh)
  const example = priceNumber !== null
    ? formatCurrency(priceNumber * 100, form.currency, i18n.language)
    : null

  return (
    <div className="page">
      <nav className="breadcrumb">
        <span>{t('settings.breadcrumbSystem')}</span>
        <span className="breadcrumb-sep">›</span>
        <span className="breadcrumb-current">{t('settings.breadcrumbSettings')}</span>
      </nav>

      <Card title={t('settings.tariffTitle')}>
        <p className="cost-note">{t('settings.tariffIntro')}</p>

        {error && <div className="alert alert--danger">{error}</div>}
        {/* "Saved" over an emptied price field is how a tariff gets wiped
            without anyone noticing — an empty price is a valid instruction to
            clear it, so the confirmation has to say which one happened. */}
        {saved && (
          <div className="alert alert--success">
            {configured ? t('settings.saved') : t('settings.cleared')}
          </div>
        )}
        {/* A banner, not a fourth line of muted prose. Whether a tariff exists is
            the one thing this page is asked, and the answer was previously
            competing for attention with the intro text above it. */}
        {!configured && <div className="alert alert--warning">{t('settings.notConfigured')}</div>}

        <TextInput
          label={t('settings.pricePerKwh')}
          id="tariff-price"
          value={form.pricePerKwh}
          onChange={(v) => edit({ pricePerKwh: v })}
          // Translated and prefixed rather than a bare "0.35": an example that
          // is a complete, plausible value is indistinguishable from an entered
          // one, and this field's whole job is to show whether a price is set.
          placeholder={t('settings.pricePlaceholder')}
        />
        <div className="stat-tile-hint">{t('settings.pricePerKwhHint')}</div>

        <TextInput
          label={t('settings.standingCharge')}
          id="tariff-standing"
          value={form.standingChargeMonth}
          onChange={(v) => edit({ standingChargeMonth: v })}
          placeholder={t('settings.standingPlaceholder')}
        />
        <div className="stat-tile-hint">{t('settings.standingChargeHint')}</div>

        <SelectField
          label={t('settings.currency')}
          id="tariff-currency"
          value={form.currency}
          onChange={(v) => edit({ currency: v })}
          options={CURRENCIES.map((c) => ({ value: c, label: c }))}
        />

        {example && (
          <div className="cost-note">{t('settings.example', { amount: example })}</div>
        )}
      </Card>

      <div className="table-footer">
        <Button onClick={save} disabled={saving}>
          {saving ? t('common.saving') : t('common.save')}
        </Button>
      </div>
    </div>
  )
}
