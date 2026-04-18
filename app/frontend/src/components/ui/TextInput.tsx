import { useState } from 'react'

interface TextInputProps {
  label: string
  id: string
  value: string
  onChange: (value: string) => void
  type?: 'text' | 'email' | 'password'
  placeholder?: string
  required?: boolean
  autoComplete?: string
  className?: string
  disabled?: boolean
}

function EyeIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" />
      <circle cx="10" cy="10" r="3" />
    </svg>
  )
}

function EyeOffIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M8.4 3.5A8.3 8.3 0 0 1 10 3.3c5.5 0 9 6.2 9 6.2a15.2 15.2 0 0 1-1.8 2.6M5.7 5.2A15 15 0 0 0 1 9.5s3.5 6.2 9 6.2a8.6 8.6 0 0 0 4.3-1.2" />
      <path d="M1 1l18 18" />
      <path d="M8.1 7.6a2.5 2.5 0 0 0 3.4 3.4" />
    </svg>
  )
}

export function TextInput({
  label,
  id,
  value,
  onChange,
  type = 'text',
  placeholder,
  required,
  autoComplete,
  className,
  disabled,
}: TextInputProps) {
  const [showPassword, setShowPassword] = useState(false)

  const isPassword = type === 'password'
  const inputType = isPassword && showPassword ? 'text' : type

  const input = (
    <input
      id={id}
      className="form-input"
      type={inputType}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      required={required}
      autoComplete={autoComplete}
      disabled={disabled}
    />
  )

  return (
    <div className={`form-group${className ? ` ${className}` : ''}`}>
      <label className="form-label" htmlFor={id}>{label}</label>
      {isPassword ? (
        <div className="form-input-wrapper">
          {input}
          <button
            type="button"
            className="form-input-toggle"
            onClick={() => setShowPassword((s) => !s)}
            tabIndex={-1}
          >
            {showPassword ? <EyeOffIcon /> : <EyeIcon />}
          </button>
        </div>
      ) : (
        input
      )}
    </div>
  )
}
