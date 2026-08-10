import { ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react'

interface PopoverProps {
  label: ReactNode
  /** Panel content; a function form receives a `close` callback. */
  children: ReactNode | ((close: () => void) => ReactNode)
  /** Class for the trigger button (defaults to the ghost-button look). */
  triggerClassName?: string
  /** Which edge the panel aligns to. */
  align?: 'left' | 'right'
}

/** A small click-toggled popover that closes on outside-click or Escape. */
export function Popover({ label, children, triggerClassName = 'btn btn--ghost btn--sm', align = 'right' }: PopoverProps) {
  const [open, setOpen] = useState(false)
  const [shift, setShift] = useState(0)
  const ref = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const shiftRef = useRef(0)

  useEffect(() => {
    if (!open) return
    const onDoc = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    const onEsc = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onEsc)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onEsc)
    }
  }, [open])

  // Keep the panel on screen. `align` pins the edge it grows from, so a trigger
  // near a viewport edge pushes the panel past it — the Reports time-range
  // picker is right-most and left-aligned, so its panel ran off the right on
  // narrow windows. Re-run on resize too, otherwise a window resized while the
  // panel is open keeps an offset measured against the old viewport.
  useLayoutEffect(() => {
    if (!open) {
      shiftRef.current = 0
      setShift(0)
      return
    }

    const clamp = () => {
      const el = panelRef.current
      if (!el) return
      const rect = el.getBoundingClientRect()
      // Undo the offset already applied so we always reason about the panel's
      // natural position; otherwise each pass would compound the previous one.
      const left = rect.left - shiftRef.current
      const right = rect.right - shiftRef.current
      const margin = 8
      // clientWidth, not innerWidth: the latter includes the scrollbar, which
      // would let the panel slide underneath it.
      const limit = document.documentElement.clientWidth - margin

      let next = 0
      if (right > limit) {
        // Never push the left edge off in the process (panel wider than viewport).
        next = -Math.min(right - limit, Math.max(0, left - margin))
      } else if (left < margin) {
        next = margin - left
      }

      shiftRef.current = next
      setShift(next)
    }

    clamp()
    window.addEventListener('resize', clamp)
    return () => window.removeEventListener('resize', clamp)
  }, [open])

  return (
    <div className="popover" ref={ref}>
      <button type="button" className={triggerClassName} onClick={() => setOpen((o) => !o)} aria-expanded={open}>
        {label}
      </button>
      {open && (
        <div
          ref={panelRef}
          className={`popover-panel popover-panel--${align}`}
          style={shift ? { transform: `translateX(${shift}px)` } : undefined}
        >
          {typeof children === 'function' ? children(() => setOpen(false)) : children}
        </div>
      )}
    </div>
  )
}
