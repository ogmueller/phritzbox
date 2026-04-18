import { useState, useRef, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../contexts/AuthContext'
import { ChangePasswordModal } from './ChangePasswordModal'

function KebabIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
      <circle cx="10" cy="4" r="2" />
      <circle cx="10" cy="10" r="2" />
      <circle cx="10" cy="16" r="2" />
    </svg>
  )
}

function KeyIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
      <path d="M10.5 1a4.5 4.5 0 0 0-4.41 5.39L1 11.5V15h3.5v-2H6v-1.5h1.5L8.61 10.39A4.5 4.5 0 1 0 10.5 1zm1.25 4.5a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5z"/>
    </svg>
  )
}

function LanguageIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
      <path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zM2.07 7.5h3.45c.06-1.3.27-2.5.6-3.5H4.4A5.98 5.98 0 0 0 2.07 7.5zm0 1h3.45c.06 1.3.27 2.5.6 3.5H4.4A5.98 5.98 0 0 1 2.07 8.5zm9.53 3.5c.33-1 .54-2.2.6-3.5h3.45A5.98 5.98 0 0 1 11.6 12zm.6-4.5c-.06-1.3-.27-2.5-.6-3.5h1.72a5.98 5.98 0 0 1 2.33 3.5h-3.45zM8 2.05c.75.42 1.5 1.67 1.9 3.45H6.1C6.5 3.72 7.25 2.47 8 2.05zM6.1 8.5h3.8c-.4 1.78-1.15 3.03-1.9 3.45-.75-.42-1.5-1.67-1.9-3.45zM6.1 7.5c.4-1.78 1.15-3.03 1.9-3.45.75.42 1.5 1.67 1.9 3.45H6.1z"/>
    </svg>
  )
}

function LogoutIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
      <path d="M6 2a1 1 0 0 0-1 1v10a1 1 0 0 0 2 0V3a1 1 0 0 0-1-1zm4.146 2.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708-.708L12.293 8.5H7.5a.5.5 0 0 1 0-1h4.793l-2.147-2.146a.5.5 0 0 1 0-.708z"/>
    </svg>
  )
}

function CheckIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
      <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/>
    </svg>
  )
}

const LANGUAGES = [
  { code: 'en', key: 'menu.language.en' as const },
  { code: 'de', key: 'menu.language.de' as const },
]

export function TopBar() {
  const { t, i18n } = useTranslation()
  const { logout } = useAuth()
  const [menuOpen, setMenuOpen] = useState(false)
  const [langOpen, setLangOpen] = useState(false)
  const [showPasswordModal, setShowPasswordModal] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!menuOpen) return
    const handleClick = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false)
        setLangOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [menuOpen])

  const currentLang = LANGUAGES.find((l) => l.code === i18n.language) ?? LANGUAGES[0]

  return (
    <>
      <header className="topbar">
        <div className="topbar-brand">
          <img
            src={`${import.meta.env.BASE_URL}phritzbox-logo.png`}
            alt="Phritzbox"
            className="topbar-logo-img"
          />
          <span className="topbar-title">Phritzbox</span>
        </div>

        <div className="topbar-spacer" />

        <div className="topbar-menu-wrapper" ref={menuRef}>
          <button
            className="topbar-menu-btn"
            onClick={() => { setMenuOpen((o) => !o); setLangOpen(false) }}
            aria-label="Menu"
          >
            <KebabIcon />
          </button>

          {menuOpen && (
            <div className="topbar-menu">
              <button
                className="topbar-menu-item"
                onClick={() => { setShowPasswordModal(true); setMenuOpen(false) }}
              >
                <span className="topbar-menu-icon"><KeyIcon /></span>
                {t('menu.changePassword')}
              </button>

              <button
                className="topbar-menu-item"
                onClick={() => setLangOpen((o) => !o)}
              >
                <span className="topbar-menu-icon"><LanguageIcon /></span>
                {t('menu.language')}
                <span className="topbar-menu-lang-current">{t(currentLang.key)}</span>
              </button>

              {langOpen && (
                <div className="topbar-menu-lang-options">
                  {LANGUAGES.map((lang) => (
                    <button
                      key={lang.code}
                      className={`topbar-menu-item topbar-menu-item--sub${lang.code === i18n.language ? ' topbar-menu-item--active' : ''}`}
                      onClick={() => {
                        i18n.changeLanguage(lang.code)
                        setLangOpen(false)
                      }}
                    >
                      {lang.code === i18n.language && (
                        <span className="topbar-menu-check"><CheckIcon /></span>
                      )}
                      <span>{t(lang.key)}</span>
                    </button>
                  ))}
                </div>
              )}

              <div className="topbar-menu-divider" />

              <button className="topbar-menu-item topbar-menu-item--danger" onClick={logout}>
                <span className="topbar-menu-icon"><LogoutIcon /></span>
                {t('menu.logout')}
              </button>
            </div>
          )}
        </div>
      </header>

      <ChangePasswordModal
        isOpen={showPasswordModal}
        onClose={() => setShowPasswordModal(false)}
      />
    </>
  )
}
