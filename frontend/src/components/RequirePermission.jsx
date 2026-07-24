import { Navigate } from 'react-router-dom'
import { useAuthStore } from '@/stores/authStore'
import { can, canAny } from '@/lib/permissions'
import EmptyState from '@/components/ui/EmptyState'

export default function RequirePermission({ permission, anyOf, children }) {
  const user = useAuthStore((s) => s.user)
  const allowed = permission ? can(user, permission) : canAny(user, anyOf ?? [])

  if (!allowed) {
    return (
      <EmptyState
        title="Accès refusé"
        description="Vous n'avez pas la permission nécessaire pour cette page."
      />
    )
  }

  return children
}

export function RequirePermissionRoute({ permission, anyOf, children }) {
  const user = useAuthStore((s) => s.user)
  const allowed = permission ? can(user, permission) : canAny(user, anyOf ?? [])

  if (!allowed) {
    return <Navigate to="/dashboard" replace />
  }

  return children
}
