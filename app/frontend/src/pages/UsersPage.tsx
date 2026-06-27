import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { getUsers, createUser, updateUser, deleteUser, User, UserPayload } from '../api/users'
import { useAuth } from '../contexts/AuthContext'
import { Card } from '../components/ui/Card'
import { DataTable } from '../components/ui/DataTable'
import { Button } from '../components/ui/Button'
import { TextInput } from '../components/ui/TextInput'
import { PencilIcon, TrashIcon } from '../components/ui/ActionIcons'

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
              sortValue: (u) => u.username,
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
              sortValue: (u) => new Date(u.createdAt).getTime(),
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
