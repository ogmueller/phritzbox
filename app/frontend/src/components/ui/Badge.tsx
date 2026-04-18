import { useTranslation } from 'react-i18next'

interface BadgeProps {
  label: string
  variant: 'success' | 'danger' | 'warning' | 'neutral'
}

export function Badge({ label, variant }: BadgeProps) {
  return <span className={`badge badge--${variant}`}>{label}</span>
}

export function OnOffBadge({ on }: { on: boolean }) {
  const { t } = useTranslation()
  return <Badge label={on ? t('badge.on') : t('badge.off')} variant={on ? 'success' : 'danger'} />
}

export function PresentBadge({ present }: { present: boolean }) {
  const { t } = useTranslation()
  return <Badge label={present ? t('badge.connected') : t('badge.offline')} variant={present ? 'success' : 'neutral'} />
}
