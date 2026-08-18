import { ReactNode } from 'react'

interface StatTileProps {
  label: string
  /** Pre-formatted — the tile never decides units, precision or locale. */
  value: ReactNode
  hint?: ReactNode
  /** `warn` for a figure that needs a caveat read alongside it. */
  tone?: 'normal' | 'warn'
}

/**
 * A single headline figure.
 *
 * Deliberately dumb: it takes strings and nodes, fetches nothing and translates
 * nothing, so it can be unit-tested and reused anywhere. A KPI is a different
 * visual form from the label/value `.detail-row` used on the device page —
 * wrapping four tiny right-aligned numbers in four card chromes reads wrong —
 * but it reuses `.detail-grid`'s grid recipe so both sit in the same layout
 * system.
 */
export function StatTile({ label, value, hint, tone = 'normal' }: StatTileProps) {
  return (
    <div className={`stat-tile${tone === 'warn' ? ' stat-tile--warn' : ''}`}>
      <div className="stat-tile-label">{label}</div>
      <div className="stat-tile-value">{value}</div>
      {hint && <div className="stat-tile-hint">{hint}</div>}
    </div>
  )
}
