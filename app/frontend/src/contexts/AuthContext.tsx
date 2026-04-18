import React, { createContext, useContext, useState, useCallback } from 'react'

interface AuthUser {
  username: string
  roles: string[]
}

interface AuthContextValue {
  token: string | null
  user: AuthUser | null
  login: (token: string) => void
  logout: () => void
  isAdmin: boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

const TOKEN_KEY = 'phritzbox_token'

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

  const login = useCallback((newToken: string) => {
    localStorage.setItem(TOKEN_KEY, newToken)
    setToken(newToken)
    setUser(parseJwtPayload(newToken))
  }, [])

  const logout = useCallback(() => {
    localStorage.removeItem(TOKEN_KEY)
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
