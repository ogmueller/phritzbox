import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../contexts/AuthContext'
import { DashboardIcon, ReportsIcon, UsersIcon, HelpIcon } from '../ui/NavIcons'

const NAV_ITEMS = [
  { to: '/dashboard', labelKey: 'nav.dashboard' as const, Icon: DashboardIcon },
  { to: '/reports',   labelKey: 'nav.reports' as const,   Icon: ReportsIcon   },
]

const ADMIN_ITEMS = [
  { to: '/users', labelKey: 'nav.users' as const, Icon: UsersIcon },
]

const BOTTOM_ITEMS = [
  { to: '/help', labelKey: 'nav.help' as const, Icon: HelpIcon },
]

export function Sidebar() {
  const { t } = useTranslation()
  const { isAdmin } = useAuth()

  return (
    <aside className="sidebar">
      <nav className="sidebar-nav">
        <div className="sidebar-top">
          {NAV_ITEMS.map(({ to, labelKey, Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) => `sidebar-link${isActive ? ' sidebar-link--active' : ''}`}
            >
              <span className="sidebar-icon"><Icon /></span>
              {t(labelKey)}
            </NavLink>
          ))}

          {isAdmin && (
            <>
              <div className="sidebar-divider" />
              {ADMIN_ITEMS.map(({ to, labelKey, Icon }) => (
                <NavLink
                  key={to}
                  to={to}
                  className={({ isActive }) => `sidebar-link${isActive ? ' sidebar-link--active' : ''}`}
                >
                  <span className="sidebar-icon"><Icon /></span>
                  {t(labelKey)}
                </NavLink>
              ))}
            </>
          )}
        </div>

        <div className="sidebar-bottom">
          <div className="sidebar-divider" />
          {BOTTOM_ITEMS.map(({ to, labelKey, Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) => `sidebar-link${isActive ? ' sidebar-link--active' : ''}`}
            >
              <span className="sidebar-icon"><Icon /></span>
              {t(labelKey)}
            </NavLink>
          ))}
        </div>
      </nav>
    </aside>
  )
}
