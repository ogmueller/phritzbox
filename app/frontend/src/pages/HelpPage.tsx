import { useTranslation } from 'react-i18next'
import { PageHeader } from '../components/layout/PageHeader'
import { Card } from '../components/ui/Card'

export function HelpPage() {
  const { t } = useTranslation()

  return (
    <div className="page">
      <PageHeader title={t('help.title')} subtitle={t('help.subtitle')} />

      <Card>
        <div className="card-body">
          <p style={{ marginBottom: 16, lineHeight: 1.6 }}>{t('help.description')}</p>

          <h3 className="help-section-title">{t('help.features')}</h3>
          <ul className="help-list">
            <li>{t('help.featureMonitor')}</li>
            <li>{t('help.featureControl')}</li>
            <li>{t('help.featureReports')}</li>
            <li>{t('help.featureUsers')}</li>
          </ul>
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
            {t('help.version')}: 1.0.0
          </div>
        </div>
      </Card>
    </div>
  )
}
