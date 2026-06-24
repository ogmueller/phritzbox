import React from 'react'

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'danger' | 'success' | 'ghost' | 'icon'
  size?: 'xs' | 'sm' | 'md'
  iconVariant?: 'edit' | 'delete'
  /** Show an inline spinner and disable the button while an async action is in flight. */
  loading?: boolean
}

export function Button({ variant = 'primary', size = 'md', iconVariant, loading = false, className = '', children, disabled, ...rest }: ButtonProps) {
  const iconClass = iconVariant ? ` btn--icon--${iconVariant}` : ''
  return (
    <button className={`btn btn--${variant} btn--${size}${iconClass} ${className}`} disabled={disabled || loading} {...rest}>
      {loading && <span className="btn-spinner" />}
      {children}
    </button>
  )
}
