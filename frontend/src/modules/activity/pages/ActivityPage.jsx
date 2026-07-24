import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import RequirePermission from '@/components/RequirePermission'
import Badge from '@/components/ui/Badge'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { activityLogsApi } from '@/modules/activity/api'

export default function ActivityPage() {
  const [search, setSearch] = useState('')

  const logsQuery = useQuery({
    queryKey: queryKeys.activityLogs({ search, per_page: 40 }),
    queryFn: () =>
      activityLogsApi.list({
        search: search || undefined,
        per_page: 40,
      }),
  })

  const { data: logs, meta } = unwrapPaginated(logsQuery.data)

  return (
    <RequirePermission anyOf={['settings.manage', 'activity.view']}>
      <PageHeader
        title="Journal d'activité"
        description="Événements métier (périmètre selon votre rôle)."
      />

      <div className="mt-2">
        <Input
          className="mb-4 max-w-sm"
          placeholder="Filtrer (description)…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        {logsQuery.isLoading ? (
          <LoadingScreen />
        ) : logs.length === 0 ? (
          <EmptyState title="Aucune activité" />
        ) : (
          <ul className="divide-y divide-border rounded-xl border border-border bg-background">
            {logs.map((log) => (
              <li key={log.id} className="px-4 py-3">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge>{log.action}</Badge>
                  <span className="text-xs text-muted-foreground">
                    {log.user?.name ?? 'Système'} · {formatDate(log.created_at, true)}
                  </span>
                </div>
                <p className="mt-1 text-sm">{log.description ?? '—'}</p>
              </li>
            ))}
          </ul>
        )}
        {meta ? (
          <p className="mt-2 text-xs text-muted-foreground">{meta.total} entrée(s)</p>
        ) : null}
      </div>
    </RequirePermission>
  )
}
