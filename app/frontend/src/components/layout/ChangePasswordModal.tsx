import { useState, FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { changeMyPassword } from '../../api/users'
import { TextInput } from '../ui/TextInput'
import { Button } from '../ui/Button'

interface ChangePasswordModalProps {
  isOpen: boolean
  onClose: () => void
}

export function ChangePasswordModal({ isOpen, onClose }: ChangePasswordModalProps) {
  const { t } = useTranslation()
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)
  const [saving, setSaving] = useState(false)

  if (!isOpen) return null

  const reset = () => {
    setCurrentPassword('')
    setNewPassword('')
    setConfirmPassword('')
    setError(null)
    setSuccess(false)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (newPassword !== confirmPassword) {
      setError(t('changePassword.mismatch'))
      return
    }
    setSaving(true)
    setError(null)
    try {
      await changeMyPassword(currentPassword, newPassword)
      setSuccess(true)
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')
    } catch {
      setError(t('changePassword.failed'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-overlay" onClick={handleClose}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h2 className="modal-title">{t('changePassword.title')}</h2>
          <button className="modal-close" onClick={handleClose}>&#x2715;</button>
        </div>

        <form onSubmit={submit}>
          <div className="modal-body">
            {error && <div className="alert alert--danger">{error}</div>}
            {success && <div className="alert alert--success">{t('changePassword.success')}</div>}

            <TextInput
              label={t('changePassword.currentPassword')}
              id="current-password"
              type="password"
              value={currentPassword}
              onChange={setCurrentPassword}
              autoComplete="current-password"
              required
            />
            <TextInput
              label={t('changePassword.newPassword')}
              id="new-password"
              type="password"
              value={newPassword}
              onChange={setNewPassword}
              autoComplete="new-password"
              required
            />
            <TextInput
              label={t('changePassword.confirmPassword')}
              id="confirm-password"
              type="password"
              value={confirmPassword}
              onChange={setConfirmPassword}
              autoComplete="new-password"
              required
            />
          </div>

          <div className="modal-footer">
            <Button variant="secondary" type="button" onClick={handleClose}>{t('common.cancel')}</Button>
            <Button type="submit" disabled={saving}>{saving ? t('common.saving') : t('common.save')}</Button>
          </div>
        </form>
      </div>
    </div>
  )
}
