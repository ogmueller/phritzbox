import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ComfortZoneCard } from './ComfortZoneCard'

const getComfortZone = vi.fn()
const updateComfortZone = vi.fn()

vi.mock('../../api/comfortZone', () => ({
  getComfortZone: () => getComfortZone(),
  updateComfortZone: (p: unknown) => updateComfortZone(p),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k, i18n: { language: 'en' } }),
}))

const cold = () => screen.getByLabelText('settings.comfortColdBelow') as HTMLInputElement
const warm = () => screen.getByLabelText('settings.comfortWarmFrom') as HTMLInputElement

describe('ComfortZoneCard', () => {
  beforeEach(() => {
    getComfortZone.mockReset()
    updateComfortZone.mockReset()
  })

  it('shows the stored zone', async () => {
    getComfortZone.mockResolvedValue({ cold: 16, warm: 20 })
    render(<ComfortZoneCard />)

    await waitFor(() => expect(cold().value).toBe('16'))
    expect(warm().value).toBe('20')
  })

  it('saves what was typed and shows what the server stored', async () => {
    getComfortZone.mockResolvedValue({ cold: 16, warm: 20 })
    updateComfortZone.mockResolvedValue({ cold: 17.5, warm: 22 })
    render(<ComfortZoneCard />)
    await waitFor(() => expect(cold().value).toBe('16'))

    await userEvent.clear(cold())
    await userEvent.type(cold(), '17,5')
    await userEvent.clear(warm())
    await userEvent.type(warm(), '22')
    await userEvent.click(screen.getByText('common.save'))

    await waitFor(() => expect(screen.getByText('settings.comfortSaved')).toBeInTheDocument())
    expect(updateComfortZone).toHaveBeenCalledWith({ coldBelow: '17,5', warmFrom: '22' })
    expect(cold().value).toBe('17.5')
  })

  it('shows the server error and keeps the typed values', async () => {
    getComfortZone.mockResolvedValue({ cold: 16, warm: 20 })
    updateComfortZone.mockRejectedValue(new Error('coldBelow must not be above warmFrom'))
    render(<ComfortZoneCard />)
    await waitFor(() => expect(cold().value).toBe('16'))

    await userEvent.clear(cold())
    await userEvent.type(cold(), '25')
    await userEvent.click(screen.getByText('common.save'))

    await waitFor(() => expect(screen.getByText('coldBelow must not be above warmFrom')).toBeInTheDocument())
    expect(cold().value).toBe('25')
  })

  it('does not let a late load overwrite an edit', async () => {
    let answer: (z: { cold: number; warm: number }) => void = () => {}
    getComfortZone.mockReturnValue(new Promise((r) => { answer = r }))
    render(<ComfortZoneCard />)

    await userEvent.type(cold(), '12')
    answer({ cold: 16, warm: 20 })

    await waitFor(() => expect(getComfortZone).toHaveBeenCalled())
    expect(cold().value).toBe('12')
  })
})
