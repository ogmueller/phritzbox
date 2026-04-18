interface LoginResponse {
  token: string
}

export async function loginRequest(username: string, password: string): Promise<string> {
  const res = await fetch('/api/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
  })

  if (!res.ok) {
    throw new Error('Invalid credentials')
  }

  const data: LoginResponse = await res.json()
  return data.token
}
