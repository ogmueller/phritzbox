import { useTranslation } from 'react-i18next'
import { PageHeader } from '../components/layout/PageHeader'
import { Card } from '../components/ui/Card'
import { useAuth } from '../contexts/AuthContext'
import { version } from '../../package.json'

const SECTIONS = [
  { titleKey: 'help.dashboardTitle', bodyKey: 'help.dashboardBody', admin: false },
  { titleKey: 'help.devicesTitle', bodyKey: 'help.devicesBody', admin: false },
  { titleKey: 'help.reportsTitle', bodyKey: 'help.reportsBody', admin: false },
  { titleKey: 'help.alertsTitle', bodyKey: 'help.alertsBody', admin: true },
  { titleKey: 'help.channelsTitle', bodyKey: 'help.channelsBody', admin: true },
  { titleKey: 'help.usersTitle', bodyKey: 'help.usersBody', admin: true },
  { titleKey: 'help.freshnessTitle', bodyKey: 'help.freshnessBody', admin: false },
] as const

export function HelpPage() {
  const { t } = useTranslation()
  const { isAdmin } = useAuth()

  const sections = SECTIONS.filter((s) => !s.admin || isAdmin)

  return (
    <div className="page">
      <PageHeader title={t('help.title')} subtitle={t('help.subtitle')} />

      <Card>
        <div className="card-body">
          <p className="help-intro">{t('help.intro')}</p>
        </div>
      </Card>

      <Card>
        <div className="card-body">
          {sections.map((s) => (
            <section key={s.titleKey} className="help-section">
              <h3 className="help-section-title">
                {t(s.titleKey)}
                {s.admin && <span className="help-admin-badge">{t('help.adminBadge')}</span>}
              </h3>
              <p className="help-body">{t(s.bodyKey)}</p>
            </section>
          ))}
        </div>
      </Card>

      <Card>
        <div className="card-body">
          <h3 className="help-section-title">{t('help.links')}</h3>
          <ul className="help-list">
            <li>
              <a href="https://avm.de/service/wissensdatenbank/" target="_blank" rel="noopener noreferrer">
                {t('help.fritzboxDocs')}
              </a>
            </li>
            <li>
              <a href="https://github.com/ogmueller/phritzbox" target="_blank" rel="noopener noreferrer">
                {t('help.githubRepo')}
              </a>
            </li>
          </ul>

          <div className="help-version">
            {t('help.version')}: {version}
          </div>
        </div>
      </Card>
    </div>
  )
}
