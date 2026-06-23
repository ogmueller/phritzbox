import React, { createContext, useContext, useState, useCallback } from 'react'
import { logoutRequest } from '../api/auth'

interface AuthUser {
  username: string
  roles: string[]
}

interface AuthContextValue {
  token: string | null
  user: AuthUser | null
  login: (token: string, refreshToken: string) => void
  logout: () => void
  isAdmin: boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

const TOKEN_KEY = 'phritzbox_token'
const REFRESH_TOKEN_KEY = 'phritzbox_refresh_token'

function parseJwtPayload(token: string): AuthUser | null {
  try {
    const payload = JSON.parse(atob(token.split('.')[1]))
    return {
      username: payload.username ?? payload.sub ?? '',
      roles: payload.roles ?? [],
    }
  } catch {
    return null
  }
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(TOKEN_KEY))
  const [user, setUser] = useState<AuthUser | null>(() => {
    const stored = localStorage.getItem(TOKEN_KEY)
    return stored ? parseJwtPayload(stored) : null
  })

  const login = useCallback((newToken: string, refreshToken: string) => {
    localStorage.setItem(TOKEN_KEY, newToken)
    localStorage.setItem(REFRESH_TOKEN_KEY, refreshToken)
    setToken(newToken)
    setUser(parseJwtPayload(newToken))
  }, [])

  const logout = useCallback(() => {
    const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY)
    if (refreshToken) {
      // Best-effort server-side invalidation; don't block the local logout.
      void logoutRequest(refreshToken)
    }
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(REFRESH_TOKEN_KEY)
    setToken(null)
    setUser(null)
  }, [])

  return (
    <AuthContext.Provider value={{ token, user, login, logout, isAdmin: user?.roles.includes('ROLE_ADMIN') ?? false }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
