import { useState, FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { loginRequest } from '../api/auth'
import { useAuth } from '../contexts/AuthContext'
import { TextInput } from '../components/ui/TextInput'
import { Button } from '../components/ui/Button'

export function LoginPage() {
  const { t } = useTranslation()
  const { login } = useAuth()
  const navigate = useNavigate()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [showForgot, setShowForgot] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const token = await loginRequest(username, password)
      login(token)
      navigate('/dashboard')
    } catch {
      setError(t('login.errorInvalid'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-page">
      <div className="login-card">
        <div className="login-banner">
          <img
            src={`${import.meta.env.BASE_URL}phritzbox-logo.png`}
            alt="Phritzbox"
            className="login-logo"
          />
          <p className="login-welcome">{t('login.welcome')}</p>
        </div>

        <div className="login-form-area">
          <form className="login-form" onSubmit={submit}>
            {error && <div className="login-error">{error}</div>}

            <TextInput
              label={t('login.username')}
              id="username"
              value={username}
              onChange={setUsername}
              autoComplete="username"
              required
            />

            <TextInput
              label={t('login.password')}
              id="password"
              value={password}
              onChange={setPassword}
              type="password"
              autoComplete="current-password"
              required
            />

            <Button type="submit" disabled={loading} className="login-btn">
              {loading ? t('login.signingIn') : t('login.signIn')}
            </Button>
          </form>

          <div className="login-forgot">
            <button
              type="button"
              className="login-forgot-link"
              onClick={() => setShowForgot((s) => !s)}
            >
              {t('login.passwordForgotten')}
            </button>
            {showForgot && (
              <p className="login-forgot-message">{t('login.passwordForgottenMessage')}</p>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
