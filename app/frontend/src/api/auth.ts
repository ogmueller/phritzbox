interface LoginResponse {
  token: string
  refresh_token: string
  refresh_token_expiration?: number
}

export interface AuthTokens {
  token: string
  refreshToken: string
}

export async function loginRequest(username: string, password: string): Promise<AuthTokens> {
  const res = await fetch('/api/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
  })

  if (!res.ok) {
    throw new Error('Invalid credentials')
  }

  const data: LoginResponse = await res.json()
  return { token: data.token, refreshToken: data.refresh_token }
}

/**
 * Best-effort invalidation of a refresh token on the server. Never throws —
 * logout must succeed locally even if the network call fails.
 */
export async function logoutRequest(refreshToken: string): Promise<void> {
  try {
    await fetch('/api/auth/logout', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    })
  } catch {
    // ignore — the token is cleared locally regardless
  }
}
