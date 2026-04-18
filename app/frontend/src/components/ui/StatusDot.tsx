interface StatusDotProps {
  active: boolean
  title?: string
}

export function StatusDot({ active, title }: StatusDotProps) {
  return (
    <span
      className={`status-dot status-dot--${active ? 'active' : 'inactive'}`}
      title={title}
    />
  )
}
