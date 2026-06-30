import { useTranslation } from 'react-i18next'
import { PageHeader } from '../components/layout/PageHeader'
import { Card } from '../components/ui/Card'
import { useAuth } from '../contexts/AuthContext'
import { version } from '../../package.json'

const SECTIONS = [
  { titleKey: 'help.dashboardTitle', bodyKey: 'help.dashboardBody', bulletsKey: 'help.dashboardBullets', admin: false },
  { titleKey: 'help.devicesTitle', bodyKey: 'help.devicesBody', bulletsKey: 'help.devicesBullets', admin: false },
  { titleKey: 'help.reportsTitle', bodyKey: 'help.reportsBody', bulletsKey: 'help.reportsBullets', admin: false },
  {
    titleKey: 'help.diagnosticsTitle',
    bodyKey: 'help.diagnosticsBody',
    bulletsKey: 'help.diagnosticsBullets',
    tipKey: 'help.diagnosticsTip',
    admin: false,
  },
  { titleKey: 'help.alertsTitle', bodyKey: 'help.alertsBody', bulletsKey: 'help.alertsBullets', admin: true },
  { titleKey: 'help.channelsTitle', bodyKey: 'help.channelsBody', bulletsKey: 'help.channelsBullets', admin: true },
  { titleKey: 'help.usersTitle', bodyKey: 'help.usersBody', bulletsKey: 'help.usersBullets', admin: true },
  { titleKey: 'help.freshnessTitle', bodyKey: 'help.freshnessBody', bulletsKey: 'help.freshnessBullets', admin: false },
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
          {sections.map((s) => {
            const bullets = t(s.bulletsKey, { returnObjects: true }) as unknown as string[]

            return (
              <section key={s.titleKey} className="help-section">
                <h3 className="help-section-title">
                  {t(s.titleKey)}
                  {s.admin && <span className="help-admin-badge">{t('help.adminBadge')}</span>}
                </h3>
                <p className="help-body">{t(s.bodyKey)}</p>
                {Array.isArray(bullets) && bullets.length > 0 && (
                  <ul className="help-bullets">
                    {bullets.map((item, i) => (
                      <li key={i}>{item}</li>
                    ))}
                  </ul>
                )}
                {'tipKey' in s && <p className="help-tip">{t(s.tipKey)}</p>}
              </section>
            )
          })}
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
            <li>
              <a href="https://github.com/ogmueller/phritzbox/issues/new" target="_blank" rel="noopener noreferrer">
                {t('help.githubIssues')}
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
