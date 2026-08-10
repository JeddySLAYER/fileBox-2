import { BrowserRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import { Toaster } from 'sonner'
import { ConfirmProvider } from '@/components/ConfirmDialog'
import { queryClient } from '@/lib/queryClient'
import AppRoutes from '@/routes'

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <ConfirmProvider>
          <AppRoutes />
          <Toaster
            position="bottom-center"
            closeButton
            duration={4200}
            gap={10}
            offset={24}
            visibleToasts={4}
            toastOptions={{
              classNames: {
                toast:
                  'rounded-xl border border-border bg-background text-foreground shadow-soft !font-[inherit]',
                title: 'text-sm font-medium',
                description: 'text-xs text-muted-foreground',
                success: '!border-emerald-200 !bg-emerald-50 !text-emerald-900',
                error: '!border-red-200 !bg-red-50 !text-red-900',
                warning: '!border-amber-200 !bg-amber-50 !text-amber-950',
                info: '!border-sky-200 !bg-sky-50 !text-sky-950',
                closeButton: '!bg-background !border-border !text-muted-foreground',
              },
            }}
          />
        </ConfirmProvider>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
