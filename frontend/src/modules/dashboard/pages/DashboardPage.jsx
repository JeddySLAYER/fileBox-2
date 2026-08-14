import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  FileText,
  FolderOpen,
  GitPullRequest,
  MessageSquare,
  Share2,
  Star,
  Users,
  Archive,
} from 'lucide-react'
import Badge from '@/components/ui/Badge'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { formatDate, statusLabel } from '@/lib/format'
import { canAny } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { dashboardApi } from '@/modules/dashboard/api'
import { useAuthStore } from '@/stores/authStore'

function previewList(items, max = 5) {
  const list = items ?? []
  return { shown: list.slice(0, max), hasMore: list.length > max }
}

function SeeMore({ to, children = 'Voir plus' }) {
  return (
    <Link to={to} className="text-xs font-medium text-primary hover:underline">
      {children}
    </Link>
  )
}

function statusTone(status) {
  if (status === 'valide' || status === 'publie') return 'success'
  if (status === 'en_validation' || status === 'propose') return 'warning'
  if (status === 'rejete') return 'danger'
  if (status === 'archive') return 'neutral'
  return 'primary'
}

function DocLink({ doc }) {
  if (!doc) return null
  return (
    <Link to={`/documents/${doc.id}`} className="truncate text-sm font-medium hover:text-primary">
      {doc.title}
    </Link>
  )
}

function ValidationRow({ item }) {
  return (
    <li className="flex items-center justify-between gap-3 py-3">
      <div className="min-w-0">
        <DocLink doc={item.document} />
        <p className="truncate text-xs text-muted-foreground">
          {item.document?.reference}
          {item.workflow_step
            ? ` · Étape ${item.workflow_step.step_order} — ${item.workflow_step.name}`
            : ''}
        </p>
      </div>
      <Badge tone="warning">En attente</Badge>
    </li>
  )
}

function FavoriteRow({ fav }) {
  if (fav.document) {
    return (
      <li className="py-3">
        <DocLink doc={fav.document} />
        <p className="truncate text-xs text-muted-foreground">{fav.document.reference}</p>
      </li>
    )
  }
  if (fav.folder) {
    return (
      <li className="py-3">
        <Link
          to={`/explorer?folder=${fav.folder.id}`}
          className="text-sm font-medium hover:text-primary"
        >
          {fav.folder.name}
        </Link>
        <p className="text-xs text-muted-foreground">Dossier</p>
      </li>
    )
  }
  return null
}

function HomeDashboard({ data }) {
  const user = useAuthStore((s) => s.user)
  const canManagePropositions = canAny(user, ['workflows.manage', 'projects.manage'])
  const recentDocs = previewList(data.recent_documents)
  const favorites = previewList(data.favorites)
  return (
    <>
      <PageHeader
        title="Bienvenue"
        description={data.scope?.label ?? 'Documents, dossiers et projets auxquels vous avez accès.'}
      />

      <div className="grid gap-4 sm:grid-cols-3">
        {[
          { label: 'Documents', value: data.counts?.documents ?? 0, icon: FileText },
          { label: 'Dossiers', value: data.counts?.folders ?? 0, icon: FolderOpen },
          {
            label: 'À valider',
            value: data.counts?.validations_pending ?? 0,
            icon: GitPullRequest,
          },
        ].map((stat) => {
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
          <div className="flex items-center justify-between gap-3">
            <h2 className="text-sm font-semibold">À valider par moi</h2>
            <div className="flex shrink-0 items-center gap-3">
              {canManagePropositions ? (
                <SeeMore to="/validations?tab=propositions">Propositions</SeeMore>
              ) : null}
              <SeeMore to="/validations?tab=suivre">À suivre</SeeMore>
            </div>
          </div>
          <ul className="mt-4 divide-y divide-border">
            {(data.pending_validations ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Rien en attente.</li>
            ) : (
              data.pending_validations.map((v) => <ValidationRow key={v.id} item={v} />)
            )}
          </ul>
        </Card>

        <Card>
          <div className="flex items-center gap-2">
            <AlertTriangle className="h-4 w-4 text-muted-foreground" />
            <h2 className="text-sm font-semibold">À reprendre</h2>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">
            Rejetés ou corrections demandées — corrigez puis reproposez / relancez le workflow.
          </p>
          <ul className="mt-4 divide-y divide-border">
            {(data.needs_attention ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun document à reprendre.</li>
            ) : (
              data.needs_attention.map((doc) => (
                <li key={doc.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <DocLink doc={doc} />
                    <p className="truncate text-xs text-muted-foreground">
                      {doc.reference} · {doc.folder?.name ?? '—'}
                    </p>
                  </div>
                  <Badge tone={statusTone(doc.status)}>{statusLabel(doc.status)}</Badge>
                </li>
              ))
            )}
          </ul>
        </Card>
      </div>

      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <Card>
          <div className="flex items-center gap-2">
            <Share2 className="h-4 w-4 text-muted-foreground" />
            <h2 className="text-sm font-semibold">Partagés avec moi</h2>
          </div>
          <ul className="mt-4 divide-y divide-border">
            {(data.shared_documents ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun partage.</li>
            ) : (
              data.shared_documents.map((doc) => (
                <li key={doc.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <DocLink doc={doc} />
                    <p className="truncate text-xs text-muted-foreground">
                      {doc.author?.name ?? '—'}
                    </p>
                  </div>
                  <Badge tone={statusTone(doc.status)}>{statusLabel(doc.status)}</Badge>
                </li>
              ))
            )}
          </ul>
        </Card>

        <Card>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Star className="h-4 w-4 text-muted-foreground" />
              <h2 className="text-sm font-semibold">Favoris</h2>
            </div>
            <SeeMore to="/favorites" />
          </div>
          <ul className="mt-4 divide-y divide-border">
            {favorites.shown.length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun favori.</li>
            ) : (
              favorites.shown.map((fav) => <FavoriteRow key={fav.id} fav={fav} />)
            )}
          </ul>
        </Card>

        <Card>
          <div className="flex items-center gap-2">
            <MessageSquare className="h-4 w-4 text-muted-foreground" />
            <h2 className="text-sm font-semibold">Commentaires récents</h2>
          </div>
          <ul className="mt-4 divide-y divide-border">
            {(data.recent_comments ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun commentaire.</li>
            ) : (
              data.recent_comments.map((c) => (
                <li key={c.id} className="py-3">
                  <DocLink doc={c.document} />
                  <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                    {c.user?.name}: {c.content}
                  </p>
                </li>
              ))
            )}
          </ul>
        </Card>
      </div>

      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <Card>
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">Documents récents</h2>
            <SeeMore to="/explorer" />
          </div>
          <ul className="mt-4 divide-y divide-border">
            {recentDocs.shown.length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun document accessible.</li>
            ) : (
              recentDocs.shown.map((doc) => (
                <li key={doc.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <DocLink doc={doc} />
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
          <h2 className="text-sm font-semibold">Dossiers récents</h2>
          <ul className="mt-4 divide-y divide-border">
            {(data.recent_folders ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun dossier accessible.</li>
            ) : (
              data.recent_folders.map((folder) => (
                <li key={folder.id} className="py-3">
                  <Link
                    to={`/explorer?folder=${folder.id}`}
                    className="text-sm font-medium hover:text-primary"
                  >
                    {folder.name}
                  </Link>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {formatDate(folder.updated_at, true)}
                  </p>
                </li>
              ))
            )}
          </ul>
        </Card>

        <Card>
          <h2 className="text-sm font-semibold">Mes projets</h2>
          <ul className="mt-4 divide-y divide-border">
            {(data.recent_projects ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun projet.</li>
            ) : (
              data.recent_projects.map((project) => (
                <li key={project.id} className="py-3">
                  <p className="text-sm font-medium">{project.name}</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {project.code}
                    {project.status ? ` · ${project.status}` : ''}
                  </p>
                </li>
              ))
            )}
          </ul>
        </Card>
      </div>
    </>
  )
}

export default function DashboardPage() {
  const user = useAuthStore((s) => s.user)
  const canViewActivity = canAny(user, ['settings.manage', 'activity.view'])

  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.dashboard,
    queryFn: dashboardApi.overview,
  })

  if (isLoading) return <LoadingScreen />
  if (isError || !data) {
    return <EmptyState title="Impossible de charger l'accueil" />
  }

  if (data.scope?.mode === 'home') {
    return <HomeDashboard data={data} />
  }

  const stats = [
    { label: 'Documents', value: data.counts.documents, icon: FileText },
    { label: 'Archivés', value: data.counts.documents_archived ?? 0, icon: Archive },
    { label: 'Utilisateurs', value: data.counts.users_active, icon: Users },
    { label: 'Validations', value: data.counts.validations_pending, icon: GitPullRequest },
    {
      label: 'En retard',
      value: data.counts.validations_blocked ?? 0,
      icon: AlertTriangle,
    },
  ]

  return (
    <>
      <PageHeader
        title="Tableau de bord"
        description={data.scope?.label ?? "Vue d'ensemble de l'activité documentaire."}
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
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
          <div className="flex items-center justify-between gap-3">
            <h2 className="text-sm font-semibold">Validations en attente</h2>
            <div className="flex shrink-0 items-center gap-3">
              {canAny(user, ['workflows.manage', 'projects.manage']) ? (
                <SeeMore to="/validations?tab=propositions">Propositions</SeeMore>
              ) : null}
              <SeeMore to="/validations?tab=suivre">À suivre</SeeMore>
            </div>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">
            Ouvrez le document pour approuver, demander une correction ou rejeter (commentaire
            obligatoire).
          </p>
          <ul className="mt-4 divide-y divide-border">
            {(data.pending_validations ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucune validation en attente.</li>
            ) : (
              data.pending_validations.map((v) => <ValidationRow key={v.id} item={v} />)
            )}
          </ul>
        </Card>

        <Card>
          <h2 className="text-sm font-semibold">Workflows bloqués</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            Étapes en attente dont l&apos;échéance est dépassée (ou &gt; 7 j sans délai fixé).
          </p>
          <ul className="mt-4 divide-y divide-border">
            {(data.blocked_validations ?? []).length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun blocage détecté.</li>
            ) : (
              data.blocked_validations.map((v) => <ValidationRow key={v.id} item={v} />)
            )}
          </ul>
        </Card>
      </div>

      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <Card>
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">Documents récents</h2>
            <SeeMore to="/explorer" />
          </div>
          <ul className="mt-4 divide-y divide-border">
            {previewList(data.recent_documents).shown.length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun document.</li>
            ) : (
              previewList(data.recent_documents).shown.map((doc) => (
                <li key={doc.id} className="flex items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <DocLink doc={doc} />
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
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">Activité récente</h2>
            {canViewActivity ? <SeeMore to="/activity" /> : null}
          </div>
          <ul className="mt-4 divide-y divide-border">
            {previewList(data.recent_activity).shown.length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucune activité.</li>
            ) : (
              previewList(data.recent_activity).shown.map((log) => (
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

        <Card>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Star className="h-4 w-4 text-muted-foreground" />
              <h2 className="text-sm font-semibold">Favoris</h2>
            </div>
            <SeeMore to="/favorites" />
          </div>
          <ul className="mt-4 divide-y divide-border">
            {previewList(data.favorites).shown.length === 0 ? (
              <li className="py-6 text-sm text-muted-foreground">Aucun favori.</li>
            ) : (
              previewList(data.favorites).shown.map((fav) => <FavoriteRow key={fav.id} fav={fav} />)
            )}
          </ul>
        </Card>
      </div>
    </>
  )
}
