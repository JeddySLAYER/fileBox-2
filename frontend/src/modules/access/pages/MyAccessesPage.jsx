import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { FileText, Folder } from 'lucide-react'
import Badge from '@/components/ui/Badge'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import Tabs from '@/components/ui/Tabs'
import { unwrapList } from '@/lib/apiHelpers'
import { formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { accessesApi } from '@/modules/access/api'

function resourceHref(accessible) {
  if (!accessible?.id) return null
  if (accessible.type === 'document') return `/documents/${accessible.id}`
  if (accessible.type === 'folder') return `/explorer?folder=${accessible.id}`
  return null
}

function resourceLabel(access) {
  const a = access.accessible
  if (!a) {
    return access.accessible_type === 'folder'
      ? `Dossier #${access.accessible_id}`
      : `Document #${access.accessible_id}`
  }
  return a.title ?? a.name ?? `${a.type} #${a.id}`
}

function AccessList({ accesses, mode }) {
  if (accesses.length === 0) {
    return (
      <EmptyState
        title={mode === 'granted' ? 'Aucun partage envoyé' : 'Aucun partage reçu'}
        description={
          mode === 'granted'
            ? 'Les documents et dossiers que vous partagez apparaîtront ici.'
            : 'Les ressources partagées avec vous apparaîtront ici.'
        }
      />
    )
  }

  return (
    <ul className="divide-y divide-border rounded-xl border border-border bg-background">
      {accesses.map((access) => {
        const a = access.accessible
        const type = a?.type ?? access.accessible_type
        const href = resourceHref(a ?? { type, id: access.accessible_id })
        const Icon = type === 'folder' ? Folder : FileText
        const meta =
          mode === 'granted'
            ? `À ${access.user?.name ?? '—'} · ${formatDate(access.created_at, true)}`
            : `Par ${access.grantor?.name ?? '—'} · ${formatDate(access.created_at, true)}`

        return (
          <li key={access.id} className="px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex min-w-0 items-start gap-3">
                <span className="mt-0.5 rounded-lg bg-muted p-2 text-muted-foreground">
                  <Icon className="h-4 w-4" />
                </span>
                <div className="min-w-0">
                  {href ? (
                    <Link to={href} className="font-medium hover:text-primary">
                      {resourceLabel(access)}
                    </Link>
                  ) : (
                    <p className="font-medium">{resourceLabel(access)}</p>
                  )}
                  <p className="text-xs text-muted-foreground">
                    {type === 'folder' ? 'Dossier' : 'Document'}
                    {a?.reference ? ` · ${a.reference}` : ''}
                    {' · '}
                    {meta}
                  </p>
                </div>
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
  )
}

export default function MyAccessesPage() {
  const [tab, setTab] = useState('received')
  const { data, isLoading } = useQuery({
    queryKey: queryKeys.myAccesses({}),
    queryFn: () => accessesApi.mine(),
  })

  const received = useMemo(
    () => unwrapList(data?.received ?? data),
    [data],
  )
  const granted = useMemo(() => unwrapList(data?.granted), [data])

  const tabs = [
    { id: 'received', label: `Reçus (${received.length})` },
    { id: 'granted', label: `Partagés par moi (${granted.length})` },
  ]

  return (
    <>
      <PageHeader
        title="Mes partages"
        description="Ressources partagées avec vous, et celles que vous avez partagées."
      />

      <div className="mb-4">
        <Tabs tabs={tabs} active={tab} onChange={setTab} />
      </div>

      {isLoading ? (
        <LoadingScreen />
      ) : tab === 'granted' ? (
        <AccessList accesses={granted} mode="granted" />
      ) : (
        <AccessList accesses={received} mode="received" />
      )}
    </>
  )
}
