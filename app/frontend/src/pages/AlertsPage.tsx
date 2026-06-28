import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { getAlerts, createAlert, updateAlert, deleteAlert, testAlert, toggleAlert, rearmAlert, getAlertEvents, Alert, AlertEvent, AlertPayload, AlertMode, AlertOperator } from '../api/alerts'
import { getChannels, Channel } from '../api/channels'
import { useDeviceContext } from '../contexts/DeviceContext'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'
import { Badge } from '../components/ui/Badge'
import { Button } from '../components/ui/Button'
import { PencilIcon, TrashIcon } from '../components/ui/ActionIcons'
import { Switch } from '../components/ui/Switch'
import { useConfirm } from '../hooks/useConfirm'
import { pushNotification } from '../notifications/bus'
import { TextInput } from '../components/ui/TextInput'
import { SelectField } from '../components/ui/SelectField'
import { CheckboxGroup } from '../components/ui/CheckboxGroup'

const METRIC_TYPES = ['temperature', 'power', 'voltage', 'energy'] as const
const UNITS: Record<string, string> = { temperature: '°C', power: 'W', voltage: 'V', energy: 'Wh' }
const OP_SYMBOLS: Record<AlertOperator, string> = { gt: '>', lt: '<', gte: '≥', lte: '≤' }

interface AlertForm {
  name: string
  enabled: boolean
  mode: AlertMode
  sid: string
  type: string
  operator: AlertOperator
  threshold: string
  compareSid: string
  compareType: string
  compareOffset: string
  durationMinutes: string
  channelIds: number[]
  cooldownMinutes: string
}

const EMPTY: AlertForm = {
  name: '', enabled: true, mode: 'threshold', sid: '', type: 'temperature', operator: 'gt',
  threshold: '', compareSid: '', compareType: 'temperature', compareOffset: '0',
  durationMinutes: '0', channelIds: [], cooldownMinutes: '0',
}

function toForm(a: Alert): AlertForm {
  return {
    name: a.name, enabled: a.enabled, mode: a.mode, sid: a.sid, type: a.type, operator: a.operator,
    threshold: a.threshold == null ? '' : String(a.threshold),
    compareSid: a.compareSid ?? '', compareType: a.compareType ?? 'temperature',
    compareOffset: String(a.compareOffset ?? 0),
    durationMinutes: String(a.durationMinutes), channelIds: a.channelIds ?? [],
    cooldownMinutes: String(a.cooldownMinutes),
  }
}

function toPayload(f: AlertForm): AlertPayload {
  return {
    name: f.name.trim(),
    enabled: f.enabled,
    mode: f.mode,
    sid: f.sid,
    type: f.type,
    operator: f.operator,
    threshold: f.mode === 'threshold' ? Number(f.threshold) : null,
    compareSid: f.mode === 'comparison' ? f.compareSid : null,
    // Both sides of a comparison use the same metric/unit (selected once).
    compareType: f.mode === 'comparison' ? f.type : null,
    compareOffset: f.mode === 'comparison' ? Number(f.compareOffset || '0') : 0,
    durationMinutes: f.mode === 'threshold' ? Number(f.durationMinutes || '0') : 0,
    channelIds: f.channelIds,
    cooldownMinutes: Number(f.cooldownMinutes || '0'),
  }
}

export function AlertsPage() {
  const { t } = useTranslation()
  const { devices } = useDeviceContext()
  const { confirm, dialog } = useConfirm()
  const [alerts, setAlerts] = useState<Alert[]>([])
  const [events, setEvents] = useState<AlertEvent[]>([])
  const [channels, setChannels] = useState<Channel[]>([])
  const [editing, setEditing] = useState<Alert | null>(null)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState<AlertForm>(EMPTY)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const load = () => getAlerts().then(setAlerts).catch(() => {})
  const loadEvents = () => getAlertEvents().then(setEvents).catch(() => {})
  useEffect(() => { load(); loadEvents(); getChannels().then(setChannels).catch(() => {}) }, [])

  const deviceName = (ain: string) => devices.find((d) => d.ain === ain)?.name ?? ain
  const metricLabel = (type: string) => t(`chart.${type}` as 'chart.temperature')

  const openCreate = () => {
    setForm({ ...EMPTY, sid: devices[0]?.ain ?? '', compareSid: devices[0]?.ain ?? '', channelIds: channels[0] ? [channels[0].id] : [] })
    setEditing(null); setCreating(true); setError(null)
  }
  const openEdit = (a: Alert) => { setForm(toForm(a)); setEditing(a); setCreating(true); setError(null) }
  const closeModal = () => { setCreating(false); setEditing(null) }

  const save = async () => {
    setSaving(true)
    setError(null)
    try {
      const payload = toPayload(form)
      if (editing) {
        await updateAlert(editing.id, payload)
      } else {
        await createAlert(payload)
      }
      await load()
      closeModal()
    } catch (e) {
      setError(e instanceof Error ? e.message : t('alerts.saveFailed'))
    } finally {
      setSaving(false)
    }
  }

  const remove = async (a: Alert) => {
    if (!(await confirm({ title: t('alerts.deleteTitle'), message: t('alerts.deleteConfirm', { name: a.name }) }))) return
    try {
      await deleteAlert(a.id)
      await load()
    } catch (e) {
      pushNotification({ severity: 'error', message: e instanceof Error ? e.message : t('alerts.deleteFailed') })
    }
  }

  const sendTest = async (a: Alert) => {
    try {
      await testAlert(a.id)
      pushNotification({ severity: 'success', message: t('alerts.testSent') })
    } catch (e) {
      pushNotification({ severity: 'error', message: e instanceof Error ? e.message : t('alerts.testFailed') })
    }
  }

  const handleToggle = async (a: Alert) => {
    const updated = await toggleAlert(a.id)
    setAlerts((prev) => prev.map((x) => (x.id === a.id ? updated : x)))
  }

  const handleRearm = async (a: Alert) => {
    const updated = await rearmAlert(a.id)
    setAlerts((prev) => prev.map((x) => (x.id === a.id ? updated : x)))
    loadEvents()
  }

  const muted = { color: 'var(--color-text-muted)' } as const

  const offsetStr = (offset: number) => (offset > 0 ? ` + ${offset}` : offset < 0 ? ` - ${Math.abs(offset)}` : '')

  const fmtNum = (v: number | null) => (v == null ? '—' : String(Number(v.toFixed(2))))

  const renderReading = (e: AlertEvent) => {
    if (e.valueDisplay == null) return '—' // e.g. a manual re-arm carries no reading
    const val = `${fmtNum(e.valueDisplay)} ${e.unit}`
    return e.compareDisplay == null ? val : `${val} ↔ ${fmtNum(e.compareDisplay)} ${e.unit}`
  }

  const renderDelivery = (e: AlertEvent) => {
    if (e.deliveries.length === 0) return <span style={muted}>—</span>
    return (
      <span className="delivery-list">
        {e.deliveries.map((d, i) => (
          <span key={i} className={`delivery delivery--${d.ok ? 'ok' : 'fail'}`} title={d.error ?? undefined}>
            {d.channel} {d.ok ? '✓' : '✗'}
          </span>
        ))}
      </span>
    )
  }

  const renderCondition = (a: Alert) => {
    const op = OP_SYMBOLS[a.operator]
    const unit = UNITS[a.type] ?? ''

    if (a.mode === 'comparison') {
      const compareUnit = UNITS[a.compareType ?? a.type] ?? ''
      // Same metric on both sides (the normal case): show the unit once at the end.
      if ((a.compareType ?? a.type) === a.type) {
        return (
          <span>
            {deviceName(a.sid)} {op} {deviceName(a.compareSid ?? '')}{offsetStr(a.compareOffset)} <span style={muted}>[{unit}]</span>
          </span>
        )
      }
      // Differing units (rare): qualify each side.
      return (
        <span>
          {deviceName(a.sid)} <span style={muted}>[{unit}]</span> {op} {deviceName(a.compareSid ?? '')}{offsetStr(a.compareOffset)} <span style={muted}>[{compareUnit}]</span>
        </span>
      )
    }

    return (
      <span>
        {deviceName(a.sid)} {op} {a.threshold} <span style={muted}>[{unit}]</span>
        {a.durationMinutes > 0 && <span style={muted}> · {t('alerts.minutesShort', { minutes: a.durationMinutes })}</span>}
      </span>
    )
  }

  const deviceOptions = devices.map((d) => ({ value: d.ain, label: d.name }))
  const metricOptions = METRIC_TYPES.map((tp) => ({ value: tp, label: metricLabel(tp) }))
  const operatorOptions: { value: AlertOperator; label: string }[] = [
    { value: 'gt', label: `${OP_SYMBOLS.gt} ${t('alerts.opAbove')}` },
    { value: 'gte', label: OP_SYMBOLS.gte },
    { value: 'lt', label: `${OP_SYMBOLS.lt} ${t('alerts.opBelow')}` },
    { value: 'lte', label: OP_SYMBOLS.lte },
  ]

  return (
    <div className="page">
      <nav className="breadcrumb">
        <span>{t('alerts.breadcrumbSystem')}</span>
        <span className="breadcrumb-sep">›</span>
        <span className="breadcrumb-current">{t('alerts.breadcrumbAlerts')}</span>
      </nav>

      <Card>
        <DataTable
          rows={alerts}
          keyFn={(a) => a.id}
          emptyMessage={t('alerts.noRules')}
          columns={[
            {
              key: 'name',
              header: t('alerts.name'),
              render: (a) => <strong>{a.name}</strong>,
              sortValue: (a) => a.name,
            },
            { key: 'condition', header: t('alerts.condition'), render: (a) => renderCondition(a) },
            { key: 'channel', header: t('alerts.channels'), width: '150px', render: (a) => a.channelNames.join(', ') || '—' },
            {
              key: 'state',
              header: t('alerts.currentState'),
              width: '150px',
              sortValue: (a) => a.lastState,
              render: (a) =>
                a.lastState === 'triggered' ? (
                  <div className="row-actions">
                    <Badge label={t('alerts.stateTriggered')} variant="danger" />
                    <Button variant="ghost" size="sm" title={t('alerts.rearmTitle')} onClick={() => handleRearm(a)}>
                      {t('alerts.rearm')}
                    </Button>
                  </div>
                ) : (
                  <Badge label={t('alerts.stateOk')} variant="neutral" />
                ),
            },
            {
              key: 'enabled',
              header: t('alerts.status'),
              width: '90px',
              sortValue: (a) => (a.enabled ? 1 : 0),
              render: (a) => (
                <Switch
                  checked={a.enabled}
                  labelOn={t('alerts.on')}
                  labelOff={t('alerts.off')}
                  onChange={() => handleToggle(a)}
                />
              ),
            },
            {
              key: 'actions',
              header: '',
              width: '150px',
              render: (a) => (
                <div className="row-actions">
                  <Button variant="ghost" size="sm" title={t('alerts.testTitle')} onClick={() => sendTest(a)}>
                    {t('alerts.test')}
                  </Button>
                  <Button variant="icon" iconVariant="edit" size="sm" title={t('alerts.editTitle')} onClick={() => openEdit(a)}>
                    <PencilIcon />
                  </Button>
                  <Button variant="icon" iconVariant="delete" size="sm" title={t('alerts.deleteTitle')} onClick={() => remove(a)}>
                    <TrashIcon />
                  </Button>
                </div>
              ),
            },
          ]}
        />
      </Card>

      <div className="table-footer">
        <Button onClick={openCreate} disabled={devices.length === 0}>{t('alerts.addRule')}</Button>
      </div>

      <p className="empty-state" style={{ textAlign: 'left', padding: '8px 0' }}>{t('alerts.latencyNote')}</p>

      <h2 className="section-title">{t('alerts.activity')}</h2>
      <Card>
        <DataTable
          rows={events}
          keyFn={(e) => e.id}
          emptyMessage={t('alerts.noActivity')}
          columns={[
            {
              key: 'time',
              header: t('alerts.time'),
              width: '170px',
              sortValue: (e) => e.createdAt,
              render: (e) => <span style={{ fontSize: '13px' }}>{new Date(e.createdAt).toLocaleString()}</span>,
            },
            { key: 'rule', header: t('alerts.rule'), sortValue: (e) => e.ruleName, render: (e) => e.ruleName },
            {
              key: 'state',
              header: t('alerts.state'),
              width: '110px',
              sortValue: (e) => e.state,
              render: (e) =>
                e.state === 'triggered'
                  ? <Badge label={t('alerts.stateTriggered')} variant="danger" />
                  : e.state === 'rearmed'
                    ? <Badge label={t('alerts.stateRearmed')} variant="neutral" />
                    : <Badge label={t('alerts.stateResolved')} variant="success" />,
            },
            { key: 'reading', header: t('alerts.reading'), render: (e) => renderReading(e) },
            { key: 'delivery', header: t('alerts.delivery'), render: (e) => renderDelivery(e) },
          ]}
        />
      </Card>

      {creating && (
        <div className="modal-overlay" onClick={closeModal}>
          <div className="modal" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h2 className="modal-title">{editing ? t('alerts.editRule') : t('alerts.newRule')}</h2>
              <button className="modal-close" onClick={closeModal}>✕</button>
            </div>

            <div className="modal-body">
              {error && <div className="alert alert--danger">{error}</div>}

              <TextInput label={t('alerts.name')} id="alert-name" value={form.name} onChange={(v) => setForm({ ...form, name: v })} />

              <SelectField
                label={t('alerts.mode')}
                id="alert-mode"
                value={form.mode}
                onChange={(v) => setForm({ ...form, mode: v as AlertMode })}
                options={[
                  { value: 'threshold', label: t('alerts.modeThreshold') },
                  { value: 'comparison', label: t('alerts.modeComparison') },
                ]}
              />

              <SelectField label={t('alerts.device')} id="alert-device" value={form.sid} onChange={(v) => setForm({ ...form, sid: v })} options={deviceOptions} />
              <SelectField label={t('alerts.metric')} id="alert-metric" value={form.type} onChange={(v) => setForm({ ...form, type: v })} options={metricOptions} />
              <SelectField label={t('alerts.operator')} id="alert-op" value={form.operator} onChange={(v) => setForm({ ...form, operator: v as AlertOperator })} options={operatorOptions} />

              {form.mode === 'threshold' ? (
                <>
                  <TextInput label={`${t('alerts.threshold')} (${UNITS[form.type] ?? ''})`} id="alert-threshold" value={form.threshold} onChange={(v) => setForm({ ...form, threshold: v })} />
                  <TextInput label={t('alerts.duration')} id="alert-duration" value={form.durationMinutes} onChange={(v) => setForm({ ...form, durationMinutes: v })} />
                </>
              ) : (
                <>
                  <SelectField label={t('alerts.compareDevice')} id="alert-cmp-device" value={form.compareSid} onChange={(v) => setForm({ ...form, compareSid: v })} options={deviceOptions} />
                  <TextInput label={`${t('alerts.offset')} (${UNITS[form.type] ?? ''})`} id="alert-offset" value={form.compareOffset} onChange={(v) => setForm({ ...form, compareOffset: v })} />
                </>
              )}

              <CheckboxGroup
                label={t('alerts.channels')}
                items={channels.map((c) => ({ key: String(c.id), label: `${c.name} (${c.type})`, checked: form.channelIds.includes(c.id) }))}
                onChange={(key, checked) => {
                  const id = Number(key)
                  setForm((f) => ({ ...f, channelIds: checked ? [...f.channelIds, id] : f.channelIds.filter((x) => x !== id) }))
                }}
              />
              {channels.length === 0 && <div className="alert alert--danger">{t('alerts.noChannels')}</div>}
              <TextInput label={t('alerts.reminder')} id="alert-cooldown" value={form.cooldownMinutes} onChange={(v) => setForm({ ...form, cooldownMinutes: v })} />

              <div className="form-group">
                <label className="checkbox-label">
                  <input type="checkbox" checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} />
                  {t('alerts.enabled')}
                </label>
              </div>
            </div>

            <div className="modal-footer">
              <Button variant="ghost" onClick={closeModal}>{t('common.cancel')}</Button>
              <Button onClick={save} disabled={saving || form.channelIds.length === 0}>{saving ? t('common.saving') : t('common.save')}</Button>
            </div>
          </div>
        </div>
      )}

      {dialog}
    </div>
  )
}
