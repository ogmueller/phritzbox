import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '../components/layout/PageHeader'
import { DeviceTable } from '../components/device/DeviceTable'
import { Button } from '../components/ui/Button'
import { useDeviceContext } from '../contexts/DeviceContext'

export function DashboardPage() {
  const { t } = useTranslation()
  const { devices, loading, error, refresh } = useDeviceContext()
  const intervalRef = useRef<ReturnType<typeof setInterval>>()

  useEffect(() => {
    intervalRef.current = setInterval(refresh, 30_000)
    return () => clearInterval(intervalRef.current)
  }, [refresh])

  return (
    <div className="page">
      <PageHeader
        title={t('dashboard.title')}
        subtitle={t('dashboard.subtitle')}
        actions={<Button variant="secondary" size="sm" onClick={refresh}>{t('common.refresh')}</Button>}
      />

      {error && <div className="alert alert--danger">{error}</div>}

      {loading && devices.length === 0 ? (
        <div className="loading-state">{t('dashboard.loadingDevices')}</div>
      ) : (
        <DeviceTable devices={devices} onRefresh={refresh} />
      )}
    </div>
  )
}
