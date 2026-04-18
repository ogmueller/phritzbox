import React from 'react'

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'danger' | 'success' | 'ghost' | 'icon'
  size?: 'xs' | 'sm' | 'md'
  iconVariant?: 'edit' | 'delete'
}

export function Button({ variant = 'primary', size = 'md', iconVariant, className = '', children, ...rest }: ButtonProps) {
  const iconClass = iconVariant ? ` btn--icon--${iconVariant}` : ''
  return (
    <button className={`btn btn--${variant} btn--${size}${iconClass} ${className}`} {...rest}>
      {children}
    </button>
  )
}
