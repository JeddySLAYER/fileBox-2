import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { FileText, FolderOpen, Users, GitPullRequest } from 'lucide-react'
import Badge from '@/components/ui/Badge'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { formatDate, statusLabel } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { dashboardApi } from '@/modules/dashboard/api'
import { can } from '@/lib/permissions'
import { useAuthStore } from '@/stores/authStore'

function statusTone(status) {
  if (status === 'valide') return 'success'
  if (status === 'en_validation') return 'warning'
  if (status === 'rejete') return 'danger'
  if (status === 'archive') return 'neutral'
  return 'primary'
}

export default function DashboardPage() {
  const user = useAuthStore((s) => s.user)
  const allowed = can(user, 'dashboard.view')

  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.dashboard,
    queryFn: dashboardApi.overview,
    enabled: allowed,
  })

  if (!allowed) {
    return (
      <>
        <PageHeader
          title="Bienvenue"
          description="Votre espace est limité aux ressources partagées avec vous."
        />
        <EmptyState
          title="Pas d'accès au tableau de bord"
          description="Utilisez l'explorateur ou la recherche pour consulter les documents auxquels vous avez accès."
          action={
            <Link to="/explorer" className="text-sm font-medium text-primary hover:underline">
              Ouvrir l&apos;explorateur
            </Link>
          }
        />
      </>
    )
  }

  if (isLoading) return <LoadingScreen />
  if (isError || !data) {
    return <EmptyState title="Impossible de charger le tableau de bord" />
  }

  const stats = [
    { label: 'Documents', value: data.counts.documents, icon: FileText },
    { label: 'Dossiers', value: data.counts.folders, icon: FolderOpen },
    { label: 'Utilisateurs', value: data.counts.users_active, icon: Users },
    { label: 'Validations', value: data.counts.validations_pending, icon: GitPullRequest },
  ]

  return (
    <>
      <PageHeader
        title="Tableau de bord"
        description="Vue d'ensemble de l'activité documentaire."
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {stats.map((stat) => {
          const Icon = stat.icon
          return (
            <Card key={stat.label} className="flex items-start justify-between">
              <div>
                <p className="text-xs text-muted-foreground">{stat.label}</p>
                <p className="mt-2 text-2xl font-semibold tracking-tight">{stat.value}</p>
              </div>
              <span className="rounded-lg bg-accent p-2 text-accent-foreground">
                <Icon className="h-4 w-4" />
              </span>
            </Card>
          )
        })}
      </div>

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="text-sm font-semibold">Documents récents</h2>
          <ul className="mt-4 divide-y divide-border">
            {(data.recent_documents ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun document.</li>
            ) : (
              data.recent_documents.map((doc) => (
                <li key={doc.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <Link
                      to={`/documents/${doc.id}`}
                      className="truncate text-sm font-medium hover:text-primary"
                    >
                      {doc.title}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                      {doc.reference} · {doc.folder?.name ?? 'Sans dossier'}
                    </p>
                  </div>
                  <Badge tone={statusTone(doc.status)}>{statusLabel(doc.status)}</Badge>
                </li>
              ))
            )}
          </ul>
        </Card>

        <Card>
          <h2 className="text-sm font-semibold">Activité récente</h2>
          <ul className="mt-4 divide-y divide-border">
            {(data.recent_activity ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucune activité.</li>
            ) : (
              data.recent_activity.map((log) => (
                <li key={log.id} className="py-3">
                  <p className="text-sm">{log.description ?? log.action}</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {log.user?.name ?? 'Système'} · {formatDate(log.created_at, true)}
                  </p>
                </li>
              ))
            )}
          </ul>
        </Card>
      </div>

      {/* IA / OCR — phase suivante : widgets suggestions / indexation */}
    </>
  )
}
