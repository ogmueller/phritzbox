interface IconProps {
  size?: number
}

export function DashboardIcon({ size = 16 }: IconProps) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width={size} height={size} fill="currentColor">
      <rect x="1" y="1" width="6" height="6" rx="1"/>
      <rect x="9" y="1" width="6" height="6" rx="1"/>
      <rect x="1" y="9" width="6" height="6" rx="1"/>
      <rect x="9" y="9" width="6" height="6" rx="1"/>
    </svg>
  )
}

export function ReportsIcon({ size = 16 }: IconProps) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width={size} height={size} fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="1,12 5,7 9,9 14,3"/>
      <line x1="1" y1="15" x2="15" y2="15"/>
    </svg>
  )
}

export function UsersIcon({ size = 16 }: IconProps) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width={size} height={size} fill="currentColor">
      <circle cx="8" cy="5" r="3"/>
      <path d="M14 14c0-3.3-2.7-6-6-6S2 10.7 2 14h12z"/>
    </svg>
  )
}

export function HelpIcon({ size = 16 }: IconProps) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width={size} height={size} fill="currentColor">
      <path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zm0 1.2A5.8 5.8 0 1 1 8 12.8 5.8 5.8 0 0 1 8 2.2zM8 4.5a2.3 2.3 0 0 0-2.3 2.1.6.6 0 1 0 1.2 0c0-.6.5-1 1.1-1s1.1.4 1.1 1-.3.9-.8 1.2c-.5.3-1.1.9-1.1 1.7v.3a.6.6 0 1 0 1.2 0v-.3c0-.4.2-.7.6-.9.6-.4 1.3-1 1.3-2a2.3 2.3 0 0 0-2.3-2.1zM8 11a.75.75 0 1 0 0 1.5A.75.75 0 0 0 8 11z"/>
    </svg>
  )
}
