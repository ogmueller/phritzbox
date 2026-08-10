import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, fireEvent, act } from '@testing-library/react'
import { Popover } from './Popover'

let viewportWidth = 400

/**
 * jsdom gives every element a zero-size rect, so the clamp needs stubbed
 * geometry to react to. `left`/`width` describe the panel's *natural* position;
 * like a real browser, the stub folds any applied translateX into the rect it
 * reports — which is what lets the resize case be exercised honestly.
 */
function stubPanelRect(left: number, width: number) {
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
    if (!this.classList.contains('popover-panel')) return new DOMRect()
    const match = (this as HTMLElement).style.transform.match(/-?[\d.]+/)
    const dx = match ? parseFloat(match[0]) : 0
    return new DOMRect(left + dx, 0, width, 100)
  })
  vi.spyOn(document.documentElement, 'clientWidth', 'get').mockImplementation(() => viewportWidth)
}

function openPanel() {
  render(
    <Popover label="pick" align="left">
      <span>panel body</span>
    </Popover>,
  )
  fireEvent.click(screen.getByRole('button', { name: 'pick' }))
  return screen.getByText('panel body').parentElement as HTMLElement
}

afterEach(() => {
  vi.restoreAllMocks()
  viewportWidth = 400
})

describe('Popover viewport clamping', () => {
  it('nudges a panel that overflows the right edge back into view', () => {
    // Panel spans 300–580 in a 400px viewport → 188px past the 8px margin.
    stubPanelRect(300, 280)
    expect(openPanel().style.transform).toBe('translateX(-188px)')
  })

  it('nudges a panel that overflows the left edge', () => {
    stubPanelRect(-30, 280)
    expect(openPanel().style.transform).toBe('translateX(38px)')
  })

  it('leaves a panel that already fits untouched', () => {
    stubPanelRect(50, 280)
    expect(openPanel().style.transform).toBe('')
  })

  it('does not push the left edge off when the panel is wider than the viewport', () => {
    // Overflows right by 208, but only 42px of room before the left margin.
    stubPanelRect(50, 550)
    expect(openPanel().style.transform).toBe('translateX(-42px)')
  })

  it('re-clamps on resize instead of keeping an offset measured against the old viewport', () => {
    stubPanelRect(300, 280)
    const panel = openPanel()
    expect(panel.style.transform).toBe('translateX(-188px)')

    // Widen the window: the panel now fits naturally, so the offset must go.
    viewportWidth = 800
    act(() => {
      window.dispatchEvent(new Event('resize'))
    })
    expect(panel.style.transform).toBe('')

    // Narrow it again: the offset must come back, not compound.
    viewportWidth = 400
    act(() => {
      window.dispatchEvent(new Event('resize'))
    })
    expect(panel.style.transform).toBe('translateX(-188px)')
  })

  it('re-measures from the natural position when narrowing an already-shifted panel', () => {
    stubPanelRect(300, 280)
    const panel = openPanel()
    expect(panel.style.transform).toBe('translateX(-188px)')

    // Narrow further *while shifted*. Measuring the already-offset rect instead
    // of the natural one would yield -20px here and leave the panel overflowing.
    viewportWidth = 360
    act(() => {
      window.dispatchEvent(new Event('resize'))
    })
    expect(panel.style.transform).toBe('translateX(-228px)')
  })
})
