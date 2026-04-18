interface DateFieldProps {
  label: string
  id: string
  value: string
  onChange: (value: string) => void
  className?: string
  disabled?: boolean
}

export function DateField({ label, id, value, onChange, className, disabled }: DateFieldProps) {
  return (
    <div className={`form-group${className ? ` ${className}` : ''}`}>
      <label className="form-label" htmlFor={id}>{label}</label>
      <input
        id={id}
        className="form-input"
        type="date"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
      />
    </div>
  )
}
