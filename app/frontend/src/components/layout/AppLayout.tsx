import { Outlet } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import { StaleDataBanner } from './StaleDataBanner'

export function AppLayout() {
  return (
    <div className="app-shell">
      <TopBar />
      <div className="app-body">
        <Sidebar />
        <main className="main-content">
          <StaleDataBanner />
          <Outlet />
        </main>
      </div>
    </div>
  )
}
