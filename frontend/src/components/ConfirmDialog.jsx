import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react'
import Button from '@/components/ui/Button'
import { cn } from '@/lib/cn'

const ConfirmContext = createContext(null)

export function ConfirmProvider({ children }) {
  const [dialog, setDialog] = useState(null)
  const resolveRef = useRef(null)

  const close = useCallback((result) => {
    resolveRef.current?.(result)
    resolveRef.current = null
    setDialog(null)
  }, [])

  const confirm = useCallback((options = {}) => {
    return new Promise((resolve) => {
      resolveRef.current = resolve
      setDialog({
        title: options.title ?? 'Confirmer',
        description: options.description ?? 'Cette action est irréversible.',
        confirmLabel: options.confirmLabel ?? 'Confirmer',
        cancelLabel: options.cancelLabel ?? 'Annuler',
        tone: options.tone ?? 'danger',
      })
    })
  }, [])

  const value = useMemo(() => confirm, [confirm])

  return (
    <ConfirmContext.Provider value={value}>
      {children}
      {dialog ? (
        <div
          className="fixed inset-0 z-[100] flex items-end justify-center p-4 sm:items-center"
          role="presentation"
          onClick={() => close(false)}
          onKeyDown={(e) => {
            if (e.key === 'Escape') close(false)
          }}
        >
          <div className="absolute inset-0 bg-foreground/40 backdrop-blur-[2px]" />
          <div
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="confirm-title"
            aria-describedby="confirm-desc"
            className="relative z-10 w-full max-w-md animate-fade-in rounded-2xl border border-border bg-background p-5 shadow-soft"
            onClick={(e) => e.stopPropagation()}
          >
            <h2 id="confirm-title" className="text-lg font-semibold tracking-tight">
              {dialog.title}
            </h2>
            <p id="confirm-desc" className="mt-2 text-sm leading-relaxed text-muted-foreground">
              {dialog.description}
            </p>
            <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button type="button" variant="secondary" size="sm" onClick={() => close(false)}>
                {dialog.cancelLabel}
              </Button>
              <Button
                type="button"
                size="sm"
                variant={dialog.tone === 'danger' ? 'danger' : 'primary'}
                className={cn(dialog.tone === 'danger' && 'border border-primary/10')}
                onClick={() => close(true)}
              >
                {dialog.confirmLabel}
              </Button>
            </div>
          </div>
        </div>
      ) : null}
    </ConfirmContext.Provider>
  )
}

export function useConfirm() {
  const ctx = useContext(ConfirmContext)
  if (!ctx) {
    throw new Error('useConfirm doit être utilisé dans ConfirmProvider')
  }
  return ctx
}
