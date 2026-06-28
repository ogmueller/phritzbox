import { ReactNode, useEffect, useRef, useState } from 'react'
import { Button } from './Button'

interface PopoverProps {
  label: ReactNode
  children: ReactNode
}

/** A small click-toggled popover that closes on outside-click or Escape. */
export function Popover({ label, children }: PopoverProps) {
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
      <Button variant="ghost" size="sm" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
        {label}
      </Button>
      {open && <div className="popover-panel">{children}</div>}
    </div>
  )
}
