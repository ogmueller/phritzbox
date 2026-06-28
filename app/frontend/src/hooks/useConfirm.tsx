import { useCallback, useState } from 'react'
import { ConfirmDialog } from '../components/ui/ConfirmDialog'

interface ConfirmRequest {
  title: string
  message: string
  confirmLabel?: string
  resolve: (ok: boolean) => void
}

/**
 * Promise-based wrapper around the styled ConfirmDialog, replacing window.confirm().
 * Usage: `const { confirm, dialog } = useConfirm()`, render `{dialog}`, then
 * `if (!(await confirm({ title, message }))) return`.
 */
export function useConfirm() {
  const [pending, setPending] = useState<ConfirmRequest | null>(null)

  const confirm = useCallback(
    (opts: { title: string; message: string; confirmLabel?: string }) =>
      new Promise<boolean>((resolve) => setPending({ ...opts, resolve })),
    [],
  )

  const close = (ok: boolean) => {
    setPending((cur) => {
      cur?.resolve(ok)
      return null
    })
  }

  const dialog = (
    <ConfirmDialog
      open={pending !== null}
      title={pending?.title ?? ''}
      message={pending?.message ?? ''}
      confirmLabel={pending?.confirmLabel}
      confirmVariant="danger"
      onConfirm={() => close(true)}
      onCancel={() => close(false)}
    />
  )

  return { confirm, dialog }
}
