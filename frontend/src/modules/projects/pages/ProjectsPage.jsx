import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { FolderKanban, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import RequirePermission from '@/components/RequirePermission'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { departmentsApi } from '@/modules/departments/api'
import { projectsApi } from '@/modules/projects/api'

export default function ProjectsPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({
    name: '',
    code: '',
    description: '',
    department_id: '',
    status: 'actif',
  })

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.projects({ search, per_page: 50 }),
    queryFn: () => projectsApi.list({ search: search || undefined, per_page: 50 }),
  })

  const departmentsQuery = useQuery({
    queryKey: queryKeys.departments({ per_page: 100 }),
    queryFn: () => departmentsApi.list({ per_page: 100 }),
    enabled: showForm,
  })

  const createProject = useMutation({
    mutationFn: () =>
      projectsApi.create({
        name: form.name,
        code: form.code || undefined,
        description: form.description || undefined,
        department_id: form.department_id ? Number(form.department_id) : null,
        status: form.status,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      setShowForm(false)
      setForm({ name: '', code: '', description: '', department_id: '', status: 'actif' })
      queryClient.invalidateQueries({ queryKey: ['projects'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeProject = useMutation({
    mutationFn: (id) => projectsApi.remove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['projects'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const { data: projects, meta } = unwrapPaginated(data)
  const departments = unwrapPaginated(departmentsQuery.data).data

  return (
    <RequirePermission permission="projects.manage">
      <PageHeader
        title="Projets"
        description="Projets et rattachements (API /projects)."
        actions={
          <Button size="sm" onClick={() => setShowForm((v) => !v)}>
            <Plus className="h-4 w-4" />
            Nouveau
          </Button>
        }
      />

      <Input
        className="mb-4 max-w-sm"
        placeholder="Rechercher…"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />

      {showForm ? (
        <Card className="mb-6">
          <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault()
              createProject.mutate()
            }}
          >
            <div>
              <Label htmlFor="p-name">Nom</Label>
              <Input
                id="p-name"
                required
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
            </div>
            <div>
              <Label htmlFor="p-code">Code</Label>
              <Input
                id="p-code"
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
              />
            </div>
            <div>
              <Label htmlFor="p-dept">Département</Label>
              <select
                id="p-dept"
                className="h-11 w-full rounded-lg border border-border bg-background px-3 text-sm"
                value={form.department_id}
                onChange={(e) => setForm({ ...form, department_id: e.target.value })}
              >
                <option value="">— Aucun —</option>
                {departments.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <Label htmlFor="p-status">Statut</Label>
              <Input
                id="p-status"
                value={form.status}
                onChange={(e) => setForm({ ...form, status: e.target.value })}
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="p-desc">Description</Label>
              <Input
                id="p-desc"
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
              />
            </div>
            <div className="flex gap-2 sm:col-span-2">
              <Button type="submit" disabled={createProject.isPending}>
                Créer
              </Button>
              <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>
                Annuler
              </Button>
            </div>
          </form>
        </Card>
      ) : null}

      {isLoading ? (
        <LoadingScreen />
      ) : projects.length === 0 ? (
        <EmptyState title="Aucun projet" />
      ) : (
        <div className="grid gap-3">
          {projects.map((project) => (
            <Card key={project.id} className="flex items-start justify-between gap-4">
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <FolderKanban className="h-4 w-4 text-primary" />
                  <Link to={`/projects/${project.id}`} className="font-medium hover:text-primary">
                    {project.name}
                  </Link>
                  {project.code ? (
                    <span className="text-xs text-muted-foreground">{project.code}</span>
                  ) : null}
                  {project.status ? <Badge>{project.status}</Badge> : null}
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {project.department?.name ?? 'Sans département'} ·{' '}
                  {project.members_count ?? 0} membre(s) · {formatDate(project.created_at)}
                </p>
                {project.description ? (
                  <p className="mt-2 text-sm text-muted-foreground">{project.description}</p>
                ) : null}
              </div>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  if (window.confirm(`Supprimer ${project.name} ?`)) {
                    removeProject.mutate(project.id)
                  }
                }}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </Card>
          ))}
          {meta ? <p className="text-xs text-muted-foreground">{meta.total} projet(s)</p> : null}
        </div>
      )}
    </RequirePermission>
  )
}
