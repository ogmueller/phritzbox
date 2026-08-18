import { pushNotification } from '../notifications/bus'

const TOKEN_KEY = 'phritzbox_token'
const REFRESH_TOKEN_KEY = 'phritzbox_refresh_token'

// Shared in-flight refresh: if several requests hit a 401 at once, they all
// await the same renewal instead of firing a stampede of refresh calls.
let refreshPromise: Promise<boolean> | null = null

async function doRefresh(): Promise<boolean> {
  const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY)
  if (!refreshToken) return false

  try {
    const res = await fetch('/api/auth/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    })
    if (!res.ok) return false

    const data = await res.json()
    if (!data.token || !data.refresh_token) return false

    localStorage.setItem(TOKEN_KEY, data.token)
    localStorage.setItem(REFRESH_TOKEN_KEY, data.refresh_token)
    return true
  } catch {
    return false
  }
}

function refreshSession(): Promise<boolean> {
  if (!refreshPromise) {
    refreshPromise = doRefresh().finally(() => {
      refreshPromise = null
    })
  }
  return refreshPromise
}

function forceLogout(): void {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(REFRESH_TOKEN_KEY)
  window.location.href = '/login'
}

/**
 * The sentence a failed request should show the operator.
 *
 * Every API error answers `{"error": "..."}`; throwing the raw body instead put
 * `{"error":"pricePerKwh must be a number"}`, braces and all, into the alert the
 * user reads. Falls back to the body as-is, then to the status, so an error page
 * or an empty 502 still says something.
 */
async function errorMessage(res: Response): Promise<string> {
  const text = await res.text()
  if (!text) return `HTTP ${res.status}`

  try {
    const body = JSON.parse(text)
    if (typeof body?.error === 'string' && body.error !== '') return body.error
  } catch {
    // Not JSON — an HTML error page or a plain string. Use it as it came.
  }

  return text
}

/**
 * Everything except reading the body: auth header, the shared silent-refresh
 * retry on 401, the global toasts, and the non-OK throw. Split out so a file
 * download gets exactly the same session handling as a JSON call — a plain
 * <a href> cannot, because the token travels in a header, not a cookie.
 */
async function rawRequest(path: string, init: RequestInit = {}, allowRetry = true): Promise<Response> {
  const token = localStorage.getItem(TOKEN_KEY)
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...(init.headers as Record<string, string>),
  }
  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  let res: Response
  try {
    res = await fetch(path, { ...init, headers })
  } catch (e) {
    // The server is unreachable (network down, DNS, CORS, etc.). Surface a
    // global toast so silent background loads don't fail invisibly.
    pushNotification({ severity: 'error', messageKey: 'errors.network' })
    throw e
  }

  if (res.status === 401) {
    // The access token expired. Try to silently renew it once and replay the
    // request; only kick the user out to /login if the refresh itself fails.
    if (allowRetry && (await refreshSession())) {
      return rawRequest(path, init, false)
    }
    forceLogout()
    throw new Error('Unauthorized')
  }

  if (res.status >= 500) {
    // Serious server-side failure — surface globally regardless of whether the
    // caller handles the thrown error inline.
    pushNotification({ severity: 'error', messageKey: 'errors.server', params: { status: res.status } })
  }

  if (!res.ok) {
    throw new Error(await errorMessage(res))
  }

  return res
}

async function request<T>(path: string, init: RequestInit = {}, allowRetry = true): Promise<T> {
  const res = await rawRequest(path, init, allowRetry)

  if (res.status === 204) return undefined as T
  return res.json() as Promise<T>
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  /** For downloads: same auth and refresh handling, body left as a Blob. */
  getBlob: async (path: string): Promise<Blob> => (await rawRequest(path)).blob(),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body: body !== undefined ? JSON.stringify(body) : undefined }),
  put: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'PUT', body: body !== undefined ? JSON.stringify(body) : undefined }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
}
