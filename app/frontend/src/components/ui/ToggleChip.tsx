import { ReactNode } from 'react'

interface ToggleChipProps {
  active: boolean
  onClick: () => void
  color?: string
  children: ReactNode
}

/** A small pill toggle: filled when active, with an optional leading colour dot. */
export function ToggleChip({ active, onClick, color, children }: ToggleChipProps) {
  return (
    <button
      type="button"
      className={`chip${active ? ' chip--active' : ''}`}
      onClick={onClick}
      aria-pressed={active}
    >
      {color && <span className="chip-dot" style={{ background: color }} />}
      {children}
    </button>
  )
}
