import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SettingsPage } from './SettingsPage'

/**
 * End-to-end through the real `api/settings.ts` and `api/client.ts`, stubbing
 * only `fetch`.
 *
 * SettingsPage.test.tsx mocks the API module, so it proves the page builds the
 * right payload but never that the payload survives the client. This one asserts
 * what actually goes on the wire — the gap where a blank body would hide.
 */
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, vars?: Record<string, unknown>) => (vars ? `${k} ${Object.values(vars).join(' ')}` : k),
    i18n: { language: 'en' },
  }),
}))

const json = (body: unknown, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  json: () => Promise.resolve(body),
  text: () => Promise.resolve(JSON.stringify(body)),
})

const STORED = { pricePerKwh: null, standingChargeMonth: 0, currency: 'EUR', configured: false }

describe('SettingsPage on the wire', () => {
  let fetchMock: ReturnType<typeof vi.fn>

  beforeEach(() => {
    localStorage.setItem('phritzbox_token', 'test-token')
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
  })

  const putBody = (): Record<string, unknown> => {
    const call = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === 'PUT')
    if (call === undefined) throw new Error('no PUT was sent')

    return JSON.parse((call[1] as RequestInit).body as string)
  }

  it('sends the typed price as a JSON body, not an empty one', async () => {
    fetchMock.mockImplementation((_url: string, init?: RequestInit) =>
      Promise.resolve(json(init?.method === 'PUT' ? { ...STORED, pricePerKwh: 0.35, configured: true } : STORED)))

    render(<SettingsPage />)
    await waitFor(() => expect(fetchMock).toHaveBeenCalled())

    await userEvent.type(screen.getByLabelText('settings.pricePerKwh'), '0.35')
    await userEvent.click(screen.getByText('common.save'))

    await waitFor(() => expect(putBody()).toEqual({
      pricePerKwh: '0.35',
      standingChargeMonth: '',
      currency: 'EUR',
    }))
  })

  it('hits the tariff endpoint with the auth header and a JSON content type', async () => {
    fetchMock.mockImplementation((_url: string, init?: RequestInit) =>
      Promise.resolve(json(init?.method === 'PUT' ? { ...STORED, pricePerKwh: 0.35, configured: true } : STORED)))

    render(<SettingsPage />)
    await waitFor(() => expect(fetchMock).toHaveBeenCalled())

    await userEvent.type(screen.getByLabelText('settings.pricePerKwh'), '0.35')
    await userEvent.click(screen.getByText('common.save'))

    await waitFor(() => {
      const call = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === 'PUT')
      expect(call?.[0]).toBe('/api/settings/tariff')
      const headers = (call?.[1] as RequestInit).headers as Record<string, string>
      expect(headers['Content-Type']).toBe('application/json')
      expect(headers['Authorization']).toBe('Bearer test-token')
    })
  })

  it('keeps the body when an expired token forces a silent refresh and replay', async () => {
    // The replay must carry the same payload. A retry that dropped it would send
    // an empty body, which the server reads as "clear the tariff" — the save
    // would report success and wipe the price.
    let puts = 0
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/auth/refresh') {
        return Promise.resolve(json({ token: 'fresh', refresh_token: 'fresh-r' }))
      }
      if (init?.method === 'PUT') {
        puts += 1
        // First attempt: the access token has expired.
        return Promise.resolve(puts === 1
          ? json(null, 401)
          : json({ ...STORED, pricePerKwh: 0.35, configured: true }))
      }
      return Promise.resolve(json(STORED))
    })
    localStorage.setItem('phritzbox_refresh_token', 'stored-refresh')

    render(<SettingsPage />)
    await waitFor(() => expect(fetchMock).toHaveBeenCalled())

    await userEvent.type(screen.getByLabelText('settings.pricePerKwh'), '0.35')
    await userEvent.click(screen.getByText('common.save'))

    await waitFor(() => expect(puts).toBe(2))
    const replay = fetchMock.mock.calls.filter((c) => (c[1] as RequestInit)?.method === 'PUT').at(-1)
    expect(JSON.parse((replay?.[1] as RequestInit).body as string)).toEqual({
      pricePerKwh: '0.35',
      standingChargeMonth: '',
      currency: 'EUR',
    })
    expect(await screen.findByText('settings.saved')).toBeTruthy()
  })

  it('sends a blank price only when the field really is blank', async () => {
    fetchMock.mockImplementation(() => Promise.resolve(json(STORED)))

    render(<SettingsPage />)
    await waitFor(() => expect(fetchMock).toHaveBeenCalled())

    await userEvent.click(screen.getByText('common.save'))

    await waitFor(() => expect(putBody().pricePerKwh).toBe(''))
  })

  it('shows a rejected tariff as a sentence, not as the JSON it arrived in', async () => {
    // The client used to throw the raw body, so a validation error reached the
    // operator as {"error":"pricePerKwh must be a number"}, braces and all.
    fetchMock.mockImplementation((_url: string, init?: RequestInit) =>
      Promise.resolve(init?.method === 'PUT'
        ? json({ error: 'pricePerKwh must be a number' }, 400)
        : json(STORED)))

    render(<SettingsPage />)
    await waitFor(() => expect(fetchMock).toHaveBeenCalled())

    await userEvent.type(screen.getByLabelText('settings.pricePerKwh'), 'cheap')
    await userEvent.click(screen.getByText('common.save'))

    expect(await screen.findByText('pricePerKwh must be a number')).toBeTruthy()
  })

  it('sends a comma-typed price through unchanged, for the endpoint to parse', async () => {
    // The German field hint asks for "0,35"; normalising is the endpoint's job,
    // so there is one authority on what a valid tariff is.
    fetchMock.mockImplementation((_url: string, init?: RequestInit) =>
      Promise.resolve(json(init?.method === 'PUT' ? { ...STORED, pricePerKwh: 0.35, configured: true } : STORED)))

    render(<SettingsPage />)
    await waitFor(() => expect(fetchMock).toHaveBeenCalled())

    await userEvent.type(screen.getByLabelText('settings.pricePerKwh'), '0,35')
    await userEvent.click(screen.getByText('common.save'))

    await waitFor(() => expect(putBody().pricePerKwh).toBe('0,35'))
    expect(await screen.findByText('settings.saved')).toBeTruthy()
  })
})
