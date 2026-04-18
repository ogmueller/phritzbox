import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './contexts/AuthContext'
import { ErrorBoundary } from './components/ErrorBoundary'
import { AppLayout } from './components/layout/AppLayout'
import { DeviceProvider } from './contexts/DeviceContext'

const LoginPage = lazy(() => import('./pages/LoginPage').then(m => ({ default: m.LoginPage })))
const DashboardPage = lazy(() => import('./pages/DashboardPage').then(m => ({ default: m.DashboardPage })))
const DeviceDetailPage = lazy(() => import('./pages/DeviceDetailPage').then(m => ({ default: m.DeviceDetailPage })))
const ReportsPage = lazy(() => import('./pages/ReportsPage').then(m => ({ default: m.ReportsPage })))
const UsersPage = lazy(() => import('./pages/UsersPage').then(m => ({ default: m.UsersPage })))
const HelpPage = lazy(() => import('./pages/HelpPage').then(m => ({ default: m.HelpPage })))

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { token } = useAuth()
  return token ? <>{children}</> : <Navigate to="/login" replace />
}

function RequireAdmin({ children }: { children: React.ReactNode }) {
  const { isAdmin } = useAuth()
  return isAdmin ? <>{children}</> : <Navigate to="/dashboard" replace />
}

function AppRoutes() {
  const { token } = useAuth()

  return (
    <Routes>
      <Route path="/login" element={token ? <Navigate to="/dashboard" replace /> : <LoginPage />} />

      <Route element={<RequireAuth><DeviceProvider><AppLayout /></DeviceProvider></RequireAuth>}>
        <Route index element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<DashboardPage />} />
        <Route path="/devices/:ain" element={<DeviceDetailPage />} />
        <Route path="/reports" element={<ReportsPage />} />
        <Route path="/users" element={<RequireAdmin><UsersPage /></RequireAdmin>} />
        <Route path="/help" element={<HelpPage />} />
      </Route>

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}

export default function App() {
  return (
    <ErrorBoundary>
      <AuthProvider>
        <BrowserRouter>
          <Suspense fallback={<div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh' }}>Loading...</div>}>
            <AppRoutes />
          </Suspense>
        </BrowserRouter>
      </AuthProvider>
    </ErrorBoundary>
  )
}
