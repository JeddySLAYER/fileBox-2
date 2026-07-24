import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import RequirePermission from '@/components/RequirePermission'
import Badge from '@/components/ui/Badge'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import Tabs from '@/components/ui/Tabs'
import { unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate } from '@/lib/format'
import { can } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { activityLogsApi } from '@/modules/activity/api'
import { useAuthStore } from '@/stores/authStore'

const TABS = [
  { id: 'business', label: 'Activité métier' },
  { id: 'system', label: 'Logs techniques' },
]

export default function ActivityPage() {
  const user = useAuthStore((s) => s.user)
  const [tab, setTab] = useState('business')
  const [search, setSearch] = useState('')
  const canSystem = can(user, 'settings.manage')

  const tabs = TABS.filter((t) => t.id === 'business' || canSystem)

  const logsQuery = useQuery({
    queryKey: queryKeys.activityLogs({ search, per_page: 40 }),
    queryFn: () =>
      activityLogsApi.list({
        search: search || undefined,
        per_page: 40,
      }),
    enabled: tab === 'business',
  })

  const systemQuery = useQuery({
    queryKey: ['activity-logs', 'system'],
    queryFn: () => activityLogsApi.system(150),
    enabled: tab === 'system' && canSystem,
  })

  const { data: logs, meta } = unwrapPaginated(logsQuery.data)

  return (
    <RequirePermission anyOf={['settings.manage', 'dashboard.view']}>
      <PageHeader
        title="Journal d'activité"
        description="Événements métier et logs techniques Laravel."
      />

      <Tabs tabs={tabs} active={tab} onChange={setTab} />

      <div className="mt-6">
        {tab === 'business' ? (
          <>
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
          </>
        ) : null}

        {tab === 'system' ? (
          systemQuery.isLoading ? (
            <LoadingScreen />
          ) : (systemQuery.data ?? []).length === 0 ? (
            <EmptyState title="Journal technique vide" />
          ) : (
            <pre className="max-h-[70vh] overflow-auto rounded-xl border border-border bg-foreground p-4 text-xs text-white/90">
              {(systemQuery.data ?? []).join('\n')}
            </pre>
          )
        ) : null}
      </div>
    </RequirePermission>
  )
}
