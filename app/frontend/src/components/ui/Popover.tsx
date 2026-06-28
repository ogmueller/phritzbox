import { ReactNode, useEffect, useRef, useState } from 'react'

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
  const ref = useRef<HTMLDivElement>(null)

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

  return (
    <div className="popover" ref={ref}>
      <button type="button" className={triggerClassName} onClick={() => setOpen((o) => !o)} aria-expanded={open}>
        {label}
      </button>
      {open && (
        <div className={`popover-panel popover-panel--${align}`}>
          {typeof children === 'function' ? children(() => setOpen(false)) : children}
        </div>
      )}
    </div>
  )
}
