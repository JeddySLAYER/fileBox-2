import { useState } from 'react'
import { Link, Outlet, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bell, Menu, X } from 'lucide-react'
import { toast } from 'sonner'
import Sidebar from '@/components/layouts/Sidebar'
import { authApi } from '@/modules/auth/api'
import { notificationsApi } from '@/modules/notifications/api'
import { queryClient, queryKeys } from '@/lib/queryClient'
import { useAuthStore } from '@/stores/authStore'

export default function AppShell() {
  const navigate = useNavigate()
  const token = useAuthStore((s) => s.token)
  const clearSession = useAuthStore((s) => s.clearSession)
  const [mobileOpen, setMobileOpen] = useState(false)

  const unreadQuery = useQuery({
    queryKey: queryKeys.unreadNotifications,
    queryFn: notificationsApi.unreadCount,
    enabled: Boolean(token),
    refetchInterval: 60_000,
  })

  const unread = unreadQuery.data ?? 0

  async function handleLogout() {
    try {
      await authApi.logout()
    } catch {
      // session déjà invalide → on nettoie quand même
    }
    clearSession()
    queryClient.clear()
    toast.success('Déconnexion')
    navigate('/login', { replace: true })
  }

  return (
    <div className="min-h-screen bg-muted/40">
      <div className="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col">
        <Sidebar onLogout={handleLogout} />
      </div>

      {mobileOpen ? (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button
            type="button"
            className="absolute inset-0 bg-foreground/40"
            aria-label="Fermer le menu"
            onClick={() => setMobileOpen(false)}
          />
          <div className="relative z-50 h-full w-64 bg-sidebar shadow-soft">
            <Sidebar onNavigate={() => setMobileOpen(false)} onLogout={handleLogout} />
          </div>
        </div>
      ) : null}

      <div className="lg:pl-64">
        <header className="sticky top-0 z-20 flex h-14 items-center justify-between border-b border-border bg-background/90 px-4 backdrop-blur sm:px-6">
          <button
            type="button"
            className="rounded-lg p-2 text-muted-foreground hover:bg-muted lg:hidden"
            onClick={() => setMobileOpen(true)}
            aria-label="Ouvrir le menu"
          >
            {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>

          <p className="hidden text-sm text-muted-foreground sm:block lg:pl-0">
            Gestion électronique des documents
          </p>

          <Link
            to="/notifications"
            className="relative rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
            aria-label="Notifications"
          >
            <Bell className="h-5 w-5" strokeWidth={1.75} />
            {unread > 0 ? (
              <span className="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground">
                {unread > 99 ? '99+' : unread}
              </span>
            ) : null}
          </Link>
        </header>

        <main className="animate-fade-in px-4 py-6 sm:px-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
