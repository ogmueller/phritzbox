import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { getUsers, createUser, updateUser, deleteUser, User, UserPayload } from '../api/users'
import { useAuth } from '../contexts/AuthContext'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'
import { Button } from '../components/ui/Button'
import { TextInput } from '../components/ui/TextInput'

const PencilIcon = () => (
  <svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
    <path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/>
  </svg>
)

const TrashIcon = () => (
  <svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
    <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
    <path fillRule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
  </svg>
)

const EMPTY: UserPayload = { username: '', email: '', password: '', roles: ['ROLE_USER'] }

export function UsersPage() {
  const { t } = useTranslation()
  const { user: currentUser } = useAuth()
  const [users, setUsers] = useState<User[]>([])
  const [editing, setEditing] = useState<User | null>(null)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState<UserPayload>(EMPTY)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const load = () => getUsers().then(setUsers).catch(() => {})
  useEffect(() => { load() }, [])

  const openCreate = () => { setForm(EMPTY); setEditing(null); setCreating(true); setError(null) }
  const openEdit = (u: User) => {
    setForm({ username: u.username, email: u.email, password: '', roles: u.roles })
    setEditing(u)
    setCreating(true)
    setError(null)
  }
  const closeModal = () => { setCreating(false); setEditing(null) }

  const save = async () => {
    setSaving(true)
    setError(null)
    try {
      const payload = { ...form }
      if (!payload.password) delete payload.password
      editing ? await updateUser(editing.id, payload) : await createUser(payload)
      await load()
      closeModal()
    } catch (e) {
      setError(e instanceof Error ? e.message : t('users.saveFailed'))
    } finally {
      setSaving(false)
    }
  }

  const remove = async (u: User) => {
    if (!confirm(t('users.deleteConfirm', { username: u.username }))) return
    try {
      await deleteUser(u.id)
      await load()
    } catch (e) {
      alert(e instanceof Error ? e.message : t('users.deleteFailed'))
    }
  }

  const toggleAdmin = (checked: boolean) =>
    setForm((f) => ({
      ...f,
      roles: checked
        ? [...new Set([...f.roles, 'ROLE_ADMIN'])]
        : f.roles.filter((r) => r !== 'ROLE_ADMIN'),
    }))

  const roleLabel = (role: string): string => {
    switch (role) {
      case 'ROLE_ADMIN': return t('users.roleAdmin')
      case 'ROLE_USER':  return t('users.roleUser')
      default: return role.replace('ROLE_', '')
    }
  }

  return (
    <div className="page">
      <nav className="breadcrumb">
        <span>{t('users.breadcrumbSystem')}</span>
        <span className="breadcrumb-sep">›</span>
        <span className="breadcrumb-current">{t('users.breadcrumbUsers')}</span>
      </nav>

      <Card>
        <DataTable
          rows={users}
          keyFn={(u) => u.id}
          emptyMessage={t('users.noUsers')}
          columns={[
            {
              key: 'username',
              header: t('users.username'),
              render: (u) => (
                <div>
                  <strong>{u.username}</strong>
                  <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: 2 }}>
                    {u.email}
                  </div>
                </div>
              ),
            },
            {
              key: 'permissions',
              header: t('users.permissions'),
              render: (u) => (
                <ul className="perm-list">
                  {u.roles.map((r) => <li key={r}>{roleLabel(r)}</li>)}
                </ul>
              ),
            },
            {
              key: 'created',
              header: t('users.created'),
              width: '110px',
              render: (u) => (
                <span style={{ fontSize: '13px', color: 'var(--color-text-muted)' }}>
                  {new Date(u.createdAt).toLocaleDateString()}
                </span>
              ),
            },
            {
              key: 'actions',
              header: '',
              width: '100px',
              render: (u) => (
                <div className="row-actions">
                  <Button
                    variant="icon"
                    iconVariant="edit"
                    size="sm"
                    title={t('users.editTitle')}
                    onClick={() => openEdit(u)}
                  >
                    <PencilIcon />
                  </Button>
                  {u.username !== currentUser?.username && (
                    <Button
                      variant="icon"
                      iconVariant="delete"
                      size="sm"
                      title={t('users.deleteTitle')}
                      onClick={() => remove(u)}
                    >
                      <TrashIcon />
                    </Button>
                  )}
                </div>
              ),
            },
          ]}
        />
      </Card>

      <div className="table-footer">
        <Button onClick={openCreate}>{t('users.addUser')}</Button>
      </div>

      {creating && (
        <div className="modal-overlay" onClick={closeModal}>
          <div className="modal" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h2 className="modal-title">{editing ? t('users.editUser') : t('users.newUser')}</h2>
              <button className="modal-close" onClick={closeModal}>✕</button>
            </div>

            <div className="modal-body">
              {error && <div className="alert alert--danger">{error}</div>}

              <TextInput
                label={t('users.username')}
                id="user-username"
                value={form.username}
                onChange={(v) => setForm({ ...form, username: v })}
              />
              <TextInput
                label={t('users.email')}
                id="user-email"
                type="email"
                value={form.email}
                onChange={(v) => setForm({ ...form, email: v })}
              />
              <TextInput
                label={editing ? t('users.passwordEditHint') : t('users.password')}
                id="user-password"
                type="password"
                value={form.password ?? ''}
                onChange={(v) => setForm({ ...form, password: v })}
              />
              <div className="form-group">
                <label className="checkbox-label">
                  <input type="checkbox" checked={form.roles.includes('ROLE_ADMIN')} onChange={(e) => toggleAdmin(e.target.checked)} />
                  {t('users.administrator')}
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
