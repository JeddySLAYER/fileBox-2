import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, Save, Users } from 'lucide-react'
import { toast } from 'sonner'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate } from '@/lib/format'
import { can } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { projectsApi } from '@/modules/projects/api'
import { usersApi } from '@/modules/users/api'
import { useAuthStore } from '@/stores/authStore'

export default function ProjectDetailPage() {
  const { id } = useParams()
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const [memberIds, setMemberIds] = useState([])

  const { data: project, isLoading, isError } = useQuery({
    queryKey: queryKeys.project(id),
    queryFn: () => projectsApi.get(id),
    enabled: Boolean(id),
  })

  const usersQuery = useQuery({
    queryKey: queryKeys.users({ per_page: 100 }),
    queryFn: () => usersApi.list({ per_page: 100 }),
    enabled: can(user, 'projects.manage'),
  })

  useEffect(() => {
    if (project?.members) {
      setMemberIds(project.members.map((m) => m.id))
    }
  }, [project])

  const syncMembers = useMutation({
    mutationFn: () => projectsApi.syncMembers(id, memberIds),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.project(id) })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const users = unwrapPaginated(usersQuery.data).data

  function toggleMember(uid) {
    setMemberIds((prev) =>
      prev.includes(uid) ? prev.filter((x) => x !== uid) : [...prev, uid],
    )
  }

  if (isLoading) return <LoadingScreen />
  if (isError || !project) return <EmptyState title="Projet introuvable" />

  return (
    <>
      <PageHeader
        title={project.name}
        description={`${project.code ?? ''} · ${project.department?.name ?? 'Sans département'}`}
        actions={
          <Button as={Link} to="/projects" variant="secondary" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Retour
          </Button>
        }
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="text-sm font-semibold">Informations</h2>
          <dl className="mt-4 space-y-3 text-sm">
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">Statut</dt>
              <dd>
                <Badge>{project.status ?? '—'}</Badge>
              </dd>
            </div>
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">Responsable</dt>
              <dd>{project.manager?.name ?? '—'}</dd>
            </div>
            <div className="flex justify-between gap-3">
              <dt className="text-muted-foreground">Créé le</dt>
              <dd>{formatDate(project.created_at)}</dd>
            </div>
          </dl>
          {project.description ? (
            <p className="mt-4 text-sm text-muted-foreground">{project.description}</p>
          ) : null}
        </Card>

        <Card>
          <div className="flex items-center justify-between gap-2">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
              <Users className="h-4 w-4" />
              Membres ({memberIds.length})
            </h2>
            {can(user, 'projects.manage') ? (
              <Button size="sm" onClick={() => syncMembers.mutate()} disabled={syncMembers.isPending}>
                <Save className="h-4 w-4" />
                Enregistrer
              </Button>
            ) : null}
          </div>

          {can(user, 'projects.manage') ? (
            <ul className="mt-4 max-h-80 space-y-1 overflow-y-auto">
              {users.map((u) => (
                <li key={u.id}>
                  <label className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-muted">
                    <input
                      type="checkbox"
                      checked={memberIds.includes(u.id)}
                      onChange={() => toggleMember(u.id)}
                    />
                    <span className="truncate">
                      {u.name}{' '}
                      <span className="text-xs text-muted-foreground">({u.email})</span>
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          ) : (
            <ul className="mt-4 space-y-2">
              {(project.members ?? []).map((m) => (
                <li key={m.id} className="text-sm">
                  {m.name} <span className="text-muted-foreground">({m.email})</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </>
  )
}
