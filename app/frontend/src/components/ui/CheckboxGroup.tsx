interface CheckboxGroupProps {
  label?: string
  items: { key: string; label: string; checked: boolean; color?: string }[]
  onChange: (key: string, checked: boolean) => void
  className?: string
}

export function CheckboxGroup({ label, items, onChange, className }: CheckboxGroupProps) {
  return (
    <div className={`checkbox-group${className ? ` ${className}` : ''}`}>
      {label && <span className="checkbox-group-label">{label}</span>}
      {items.map((item) => (
        <label key={item.key} className="checkbox-group-item">
          <input
            type="checkbox"
            checked={item.checked}
            onChange={(e) => onChange(item.key, e.target.checked)}
          />
          <span style={item.color ? { color: item.color } : undefined}>{item.label}</span>
        </label>
      ))}
    </div>
  )
}
