interface SelectFieldProps {
  label: string
  id: string
  value: string
  onChange: (value: string) => void
  options: { value: string; label: string }[]
  className?: string
  disabled?: boolean
}

export function SelectField({ label, id, value, onChange, options, className, disabled }: SelectFieldProps) {
  return (
    <div className={`form-group${className ? ` ${className}` : ''}`}>
      <label className="form-label" htmlFor={id}>{label}</label>
      <select
        id={id}
        className="form-input"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
    </div>
  )
}
