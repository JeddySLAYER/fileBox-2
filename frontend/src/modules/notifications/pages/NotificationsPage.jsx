import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCheck } from 'lucide-react'
import { toast } from 'sonner'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { notificationsApi } from '@/modules/notifications/api'

function notificationHref(n) {
  if (n.href) return n.href
  const data = n.data ?? {}
  if (data.href) return data.href
  if (data.document_id || n.document_id) {
    return `/documents/${data.document_id || n.document_id}?tab=validations`
  }
  if (String(n.type || data.type || '').startsWith('validation.')) {
    return '/validations?tab=suivre'
  }
  return null
}

export default function NotificationsPage() {
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.notifications({ per_page: 50 }),
    queryFn: () => notificationsApi.list({ per_page: 50 }),
  })

  const markRead = useMutation({
    mutationFn: (id) => notificationsApi.markAsRead(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      queryClient.invalidateQueries({ queryKey: queryKeys.unreadNotifications })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const markAll = useMutation({
    mutationFn: () => notificationsApi.markAllAsRead(),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      queryClient.invalidateQueries({ queryKey: queryKeys.unreadNotifications })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const { data: notifications } = unwrapPaginated(data)
  const unread = notifications.filter((n) => !n.read_at).length

  return (
    <>
      <PageHeader
        title="Notifications"
        description="Alertes importantes : accès, validations, échéances, commentaires et publications."
        actions={
          unread > 0 ? (
            <Button size="sm" variant="secondary" onClick={() => markAll.mutate()}>
              <CheckCheck className="h-4 w-4" />
              Tout marquer lu
            </Button>
          ) : null
        }
      />

      {isLoading ? (
        <LoadingScreen />
      ) : notifications.length === 0 ? (
        <EmptyState title="Aucune notification" />
      ) : (
        <ul className="divide-y divide-border rounded-xl border border-border bg-background">
          {notifications.map((n) => {
            const href = notificationHref(n)
            const title = n.title ?? n.data?.title ?? n.type
            const message = n.message ?? n.data?.message
            return (
            <li
              key={n.id}
              className={`flex items-start justify-between gap-3 px-4 py-3 ${
                n.read_at ? '' : 'bg-accent/40'
              }`}
            >
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <Bell className="h-4 w-4 shrink-0 text-muted-foreground" />
                  {href ? (
                    <Link
                      to={href}
                      className="text-sm font-medium hover:text-primary"
                      onClick={() => {
                        if (!n.read_at) markRead.mutate(n.id)
                      }}
                    >
                      {title}
                    </Link>
                  ) : (
                    <p className="text-sm font-medium">{title}</p>
                  )}
                  {!n.read_at ? <Badge tone="primary">Non lu</Badge> : null}
                </div>
                {message ? (
                  <p className="mt-1 text-sm text-muted-foreground">{message}</p>
                ) : null}
                <p className="mt-1 text-xs text-muted-foreground">
                  {formatDate(n.created_at, true)}
                </p>
              </div>
              {!n.read_at ? (
                <Button size="sm" variant="ghost" onClick={() => markRead.mutate(n.id)}>
                  Lu
                </Button>
              ) : null}
            </li>
            )
          })}
        </ul>
      )}
    </>
  )
}
