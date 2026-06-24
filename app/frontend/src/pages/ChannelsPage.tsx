import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { getChannels, createChannel, updateChannel, deleteChannel, Channel, ChannelPayload, ChannelType } from '../api/channels'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'
import { Button } from '../components/ui/Button'
import { PencilIcon, TrashIcon } from '../components/ui/ActionIcons'
import { TextInput } from '../components/ui/TextInput'
import { SelectField } from '../components/ui/SelectField'

const EMPTY: ChannelPayload = { name: '', type: 'email', target: '', secret: '', enabled: true }

const TYPE_OPTIONS: { value: ChannelType; labelKey: string }[] = [
  { value: 'email', labelKey: 'channels.typeEmail' },
  { value: 'webhook', labelKey: 'channels.typeWebhook' },
  { value: 'pushover', labelKey: 'channels.typePushover' },
  { value: 'telegram', labelKey: 'channels.typeTelegram' },
  { value: 'ntfy', labelKey: 'channels.typeNtfy' },
  { value: 'discord', labelKey: 'channels.typeDiscord' },
  { value: 'gotify', labelKey: 'channels.typeGotify' },
  { value: 'slack', labelKey: 'channels.typeSlack' },
]

const TARGET_LABEL: Record<ChannelType, string> = {
  email: 'channels.targetEmail',
  webhook: 'channels.targetWebhook',
  pushover: 'channels.targetPushover',
  telegram: 'channels.targetTelegram',
  ntfy: 'channels.targetNtfy',
  discord: 'channels.targetDiscord',
  gotify: 'channels.targetGotify',
  slack: 'channels.targetSlack',
}

// Types that expose a secret/token field, mapped to their label key.
const SECRET_LABEL: Partial<Record<ChannelType, string>> = {
  pushover: 'channels.secretPushover',
  telegram: 'channels.secretTelegram',
  gotify: 'channels.secretGotify',
  ntfy: 'channels.secretNtfy',
}

export function ChannelsPage() {
  const { t } = useTranslation()
  const [channels, setChannels] = useState<Channel[]>([])
  const [editing, setEditing] = useState<Channel | null>(null)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState<ChannelPayload>(EMPTY)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const load = () => getChannels().then(setChannels).catch(() => {})
  useEffect(() => { load() }, [])

  const openCreate = () => { setForm(EMPTY); setEditing(null); setCreating(true); setError(null) }
  const openEdit = (c: Channel) => {
    setForm({ name: c.name, type: c.type, target: c.target, secret: c.secret ?? '', enabled: c.enabled })
    setEditing(c); setCreating(true); setError(null)
  }
  const closeModal = () => { setCreating(false); setEditing(null) }

  const save = async () => {
    setSaving(true)
    setError(null)
    try {
      editing ? await updateChannel(editing.id, form) : await createChannel(form)
      await load()
      closeModal()
    } catch (e) {
      setError(e instanceof Error ? e.message : t('channels.saveFailed'))
    } finally {
      setSaving(false)
    }
  }

  const remove = async (c: Channel) => {
    if (!confirm(t('channels.deleteConfirm', { name: c.name }))) return
    try {
      await deleteChannel(c.id)
      await load()
    } catch (e) {
      alert(e instanceof Error ? e.message : t('channels.deleteFailed'))
    }
  }

  return (
    <div className="page">
      <nav className="breadcrumb">
        <span>{t('channels.breadcrumbSystem')}</span>
        <span className="breadcrumb-sep">›</span>
        <span className="breadcrumb-current">{t('channels.breadcrumbChannels')}</span>
      </nav>

      <Card>
        <DataTable
          rows={channels}
          keyFn={(c) => c.id}
          emptyMessage={t('channels.noChannels')}
          columns={[
            { key: 'name', header: t('channels.name'), render: (c) => <strong>{c.name}</strong> },
            { key: 'type', header: t('channels.type'), width: '110px', render: (c) => c.type },
            { key: 'target', header: t('channels.target'), render: (c) => c.target },
            {
              key: 'actions',
              header: '',
              width: '100px',
              render: (c) => (
                <div className="row-actions">
                  <Button variant="icon" iconVariant="edit" size="sm" title={t('channels.editTitle')} onClick={() => openEdit(c)}><PencilIcon /></Button>
                  <Button variant="icon" iconVariant="delete" size="sm" title={t('channels.deleteTitle')} onClick={() => remove(c)}><TrashIcon /></Button>
                </div>
              ),
            },
          ]}
        />
      </Card>

      <div className="table-footer">
        <Button onClick={openCreate}>{t('channels.addChannel')}</Button>
      </div>

      {creating && (
        <div className="modal-overlay" onClick={closeModal}>
          <div className="modal" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h2 className="modal-title">{editing ? t('channels.editChannel') : t('channels.newChannel')}</h2>
              <button className="modal-close" onClick={closeModal}>✕</button>
            </div>

            <div className="modal-body">
              {error && <div className="alert alert--danger">{error}</div>}

              <TextInput label={t('channels.name')} id="channel-name" value={form.name} onChange={(v) => setForm({ ...form, name: v })} />
              <SelectField
                label={t('channels.type')}
                id="channel-type"
                value={form.type}
                onChange={(v) => setForm({ ...form, type: v as ChannelType })}
                options={TYPE_OPTIONS.map((o) => ({ value: o.value, label: t(o.labelKey as 'channels.typeEmail') }))}
              />
              <TextInput
                label={t(TARGET_LABEL[form.type] as 'channels.targetEmail')}
                id="channel-target"
                value={form.target}
                onChange={(v) => setForm({ ...form, target: v })}
              />
              {SECRET_LABEL[form.type] && (
                <TextInput
                  label={t(SECRET_LABEL[form.type] as 'channels.secretPushover')}
                  id="channel-secret"
                  value={form.secret ?? ''}
                  onChange={(v) => setForm({ ...form, secret: v })}
                />
              )}
              <div className="form-group">
                <label className="checkbox-label">
                  <input type="checkbox" checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} />
                  {t('channels.enabled')}
                </label>
              </div>
            </div>

            <div className="modal-footer">
              <Button variant="ghost" onClick={closeModal}>{t('common.cancel')}</Button>
              <Button onClick={save} disabled={saving}>{saving ? t('common.saving') : t('common.save')}</Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
