import React from 'react'

interface State {
  hasError: boolean
}

export class ErrorBoundary extends React.Component<{ children: React.ReactNode }, State> {
  state: State = { hasError: false }

  static getDerivedStateFromError(): State {
    return { hasError: true }
  }

  render() {
    if (this.state.hasError) {
      return (
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh', background: 'var(--color-background, #f5f0e8)' }}>
          <div style={{ textAlign: 'center', padding: '2rem' }}>
            <h1 style={{ fontSize: '1.5rem', marginBottom: '1rem', color: 'var(--color-text, #333)' }}>Something went wrong</h1>
            <p style={{ marginBottom: '1.5rem', color: 'var(--color-text-muted, #666)' }}>An unexpected error occurred.</p>
            <button
              onClick={() => window.location.reload()}
              style={{ padding: '0.5rem 1.5rem', background: 'var(--color-primary, #0046A8)', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer', fontSize: '1rem' }}
            >
              Reload
            </button>
          </div>
        </div>
      )
    }

    return this.props.children
  }
}
