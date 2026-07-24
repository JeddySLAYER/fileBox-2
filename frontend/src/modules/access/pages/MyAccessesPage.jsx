import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import Badge from '@/components/ui/Badge'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { unwrapList } from '@/lib/apiHelpers'
import { formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { accessesApi } from '@/modules/access/api'

export default function MyAccessesPage() {
  const { data, isLoading } = useQuery({
    queryKey: queryKeys.myAccesses({}),
    queryFn: () => accessesApi.mine(),
  })

  const accesses = unwrapList(data)

  return (
    <>
      <PageHeader
        title="Mes partages"
        description="Ressources auxquelles on vous a donné un accès spécifique."
      />

      {isLoading ? (
        <LoadingScreen />
      ) : accesses.length === 0 ? (
        <EmptyState title="Aucun accès partagé" />
      ) : (
        <ul className="divide-y divide-border rounded-xl border border-border bg-background">
          {accesses.map((access) => {
            const a = access.accessible
            const href =
              a?.type === 'document'
                ? `/documents/${a.id}`
                : a?.type === 'folder'
                  ? `/explorer?folder=${a.id}`
                  : null

            return (
              <li key={access.id} className="px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    {href ? (
                      <Link to={href} className="font-medium hover:text-primary">
                        {a?.title ?? a?.name ?? `${access.accessible_type} #${access.accessible_id}`}
                      </Link>
                    ) : (
                      <p className="font-medium">
                        {access.accessible_type} #{access.accessible_id}
                      </p>
                    )}
                    <p className="text-xs text-muted-foreground">
                      Par {access.grantor?.name ?? '—'} · {formatDate(access.created_at, true)}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-1">
                    {(access.abilities ?? []).map((ab) => (
                      <Badge key={ab}>{ab}</Badge>
                    ))}
                    <Badge tone={access.is_active ? 'success' : 'danger'}>
                      {access.is_active ? 'Actif' : 'Expiré'}
                    </Badge>
                  </div>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </>
  )
}
