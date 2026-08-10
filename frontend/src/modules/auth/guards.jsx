import { useEffect, useState } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { authApi } from '@/modules/auth/api'
import { useAuthStore } from '@/stores/authStore'
import LoadingScreen from '@/components/ui/LoadingScreen'

export function RequireAuth() {
  const location = useLocation()
  const token = useAuthStore((s) => s.token)
  const user = useAuthStore((s) => s.user)
  const mustChangePassword = useAuthStore((s) => s.mustChangePassword)
  const setUser = useAuthStore((s) => s.setUser)
  const clearSession = useAuthStore((s) => s.clearSession)
  const [ready, setReady] = useState(() => Boolean(user))

  useEffect(() => {
    if (!token) return

    let cancelled = false
    authApi
      .me()
      .then((data) => {
        if (!cancelled) setUser(data.user)
      })
      .catch(() => {
        if (!cancelled) clearSession()
      })
      .finally(() => {
        if (!cancelled) setReady(true)
      })

    return () => {
      cancelled = true
    }
  }, [token, setUser, clearSession])

  if (!token) {
    return <Navigate to="/login" replace state={{ from: location }} />
  }

  if (!ready) {
    return <LoadingScreen label="Vérification de la session…" />
  }

  if (mustChangePassword && location.pathname !== '/change-password') {
    return <Navigate to="/change-password" replace />
  }

  return <Outlet />
}

export function GuestOnly() {
  const token = useAuthStore((s) => s.token)
  const mustChangePassword = useAuthStore((s) => s.mustChangePassword)

  if (token && mustChangePassword) {
    return <Navigate to="/change-password" replace />
  }

  if (token) {
    return <Navigate to="/dashboard" replace />
  }

  return <Outlet />
}
