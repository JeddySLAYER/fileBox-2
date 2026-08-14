import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import Button from '@/components/ui/Button'
import { cn } from '@/lib/cn'

const FeedbackContext = createContext(null)

function DialogShell({ title, description, children, onBackdrop }) {
  return (
    <div
      className="fixed inset-0 z-[100] flex items-end justify-center p-4 sm:items-center"
      role="presentation"
      onClick={onBackdrop}
      onKeyDown={(e) => {
        if (e.key === 'Escape') onBackdrop()
      }}
    >
      <div className="absolute inset-0 bg-foreground/40 backdrop-blur-[2px]" />
      <div
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="feedback-title"
        aria-describedby="feedback-desc"
        className="relative z-10 w-full max-w-md animate-fade-in rounded-2xl border border-border bg-background p-5 shadow-soft"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 id="feedback-title" className="text-lg font-semibold tracking-tight">
          {title}
        </h2>
        <p id="feedback-desc" className="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground">
          {description}
        </p>
        {children}
      </div>
    </div>
  )
}

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
        mode: 'confirm',
        title: options.title ?? 'Confirmer',
        description: options.description ?? 'Cette action est irréversible.',
        confirmLabel: options.confirmLabel ?? 'Confirmer',
        cancelLabel: options.cancelLabel ?? 'Annuler',
        tone: options.tone ?? 'danger',
      })
    })
  }, [])

  const alert = useCallback((options = {}) => {
    return new Promise((resolve) => {
      resolveRef.current = resolve
      setDialog({
        mode: 'alert',
        title: options.title ?? 'Information',
        description: options.description ?? '',
        confirmLabel: options.confirmLabel ?? 'OK',
        tone: options.tone ?? 'danger',
      })
    })
  }, [])

  useEffect(() => {
    const originalError = toast.error.bind(toast)
    toast.error = (message) => {
      const description = typeof message === 'string' && message.trim()
        ? message
        : 'Une erreur est survenue. Réessayez ou contactez un administrateur.'
      alert({
        title: 'Action impossible',
        description,
        tone: 'danger',
        confirmLabel: 'OK',
      })
    }
    return () => {
      toast.error = originalError
    }
  }, [alert])

  const value = useMemo(() => ({ confirm, alert }), [confirm, alert])

  return (
    <FeedbackContext.Provider value={value}>
      {children}
      {dialog ? (
        <DialogShell
          title={dialog.title}
          description={dialog.description}
          onBackdrop={() => close(dialog.mode === 'confirm' ? false : true)}
        >
          <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            {dialog.mode === 'confirm' ? (
              <Button type="button" variant="secondary" size="sm" onClick={() => close(false)}>
                {dialog.cancelLabel}
              </Button>
            ) : null}
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
        </DialogShell>
      ) : null}
    </FeedbackContext.Provider>
  )
}

function useFeedback() {
  const ctx = useContext(FeedbackContext)
  if (!ctx) {
    throw new Error('useConfirm / useAlert doivent être utilisés dans ConfirmProvider')
  }
  return ctx
}

export function useConfirm() {
  return useFeedback().confirm
}

export function useAlert() {
  return useFeedback().alert
}
