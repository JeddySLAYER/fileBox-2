import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Pencil, Plus, Trash2, UserPlus } from 'lucide-react'
import { toast } from 'sonner'
import { useConfirm } from '@/components/ConfirmDialog'
import RequirePermission from '@/components/RequirePermission'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import DataTable from '@/components/ui/DataTable'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import Modal from '@/components/ui/Modal'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapList, unwrapPaginated, PAGE_SIZE } from '@/lib/apiHelpers'
import { formatDate } from '@/lib/format'
import { can, hasRole } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { departmentsApi } from '@/modules/departments/api'
import { projectsApi } from '@/modules/projects/api'
import { useAuthStore } from '@/stores/authStore'

const STATUSES = [
  { value: 'actif', label: 'Actif' },
  { value: 'en_pause', label: 'En pause' },
  { value: 'termine', label: 'Terminé' },
  { value: 'archive', label: 'Archivé' },
]

const emptyForm = {
  name: '',
  code: '',
  description: '',
  department_ids: [],
  status: 'actif',
  starts_at: '',
  ends_at: '',
}

const selectClass = 'h-11 w-full rounded-lg border border-border bg-background px-3 text-sm'

function statusLabel(status) {
  return STATUSES.find((s) => s.value === status)?.label ?? status
}

function canPickDepartments(user) {
  return (
    hasRole(user, 'administrateur') ||
    hasRole(user, 'direction') ||
    hasRole(user, 'chef_projet')
  )
}

export default function ProjectsPage() {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const canManage = can(user, 'projects.manage')
  const pickDepartments = canPickDepartments(user)
  const lockedDeptId = user?.department_id ? String(user.department_id) : null

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [membersProject, setMembersProject] = useState(null)
  const [memberIds, setMemberIds] = useState([])
  const [memberSearch, setMemberSearch] = useState('')
  const [memberFilter, setMemberFilter] = useState('all') // all | selected | dept

  const listParams = {
    search: search || undefined,
    per_page: PAGE_SIZE,
    page,
  }

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.projects(listParams),
    queryFn: () => projectsApi.list(listParams),
  })

  const departmentsQuery = useQuery({
    queryKey: queryKeys.departments({ per_page: 100 }),
    queryFn: () => departmentsApi.list({ per_page: 100 }),
    enabled: showForm && canManage && pickDepartments,
  })

  const candidatesQuery = useQuery({
    queryKey: ['projects', membersProject?.id, 'member-candidates'],
    queryFn: () => projectsApi.memberCandidates(membersProject.id),
    enabled: Boolean(membersProject?.id) && canManage,
  })

  const createProject = useMutation({
    mutationFn: () => projectsApi.create(payloadFromForm(form, { pickDepartments, lockedDeptId })),
    onSuccess: (res) => {
      toast.success(res.message)
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['projects'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const updateProject = useMutation({
    mutationFn: () =>
      projectsApi.update(editingId, payloadFromForm(form, { pickDepartments, lockedDeptId })),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Projet mis à jour.')
      closeForm()
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

  const syncMembers = useMutation({
    mutationFn: () => projectsApi.syncMembers(membersProject.id, memberIds.map(Number)),
    onSuccess: (res) => {
      toast.success(res.message)
      closeMembers()
      queryClient.invalidateQueries({ queryKey: ['projects'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const { data: projects, meta } = unwrapPaginated(data)
  const departments = unwrapList(departmentsQuery.data)
  const eligibleMembers = Array.isArray(candidatesQuery.data) ? candidatesQuery.data : []
  const saving = createProject.isPending || updateProject.isPending

  const projectDeptIds = useMemo(() => {
    if (!membersProject) return new Set()
    const depts = membersProject.departments?.length
      ? membersProject.departments
      : membersProject.department
        ? [membersProject.department]
        : []
    return new Set(depts.map((d) => Number(d.id)))
  }, [membersProject])

  const lockedMemberIds = useMemo(() => {
    if (!membersProject) return new Set()
    const ids = new Set()
    if (membersProject.created_by) ids.add(String(membersProject.created_by))
    if (membersProject.manager_id) ids.add(String(membersProject.manager_id))
    if (membersProject.root_folder?.created_by) {
      ids.add(String(membersProject.root_folder.created_by))
    }
    for (const d of membersProject.departments ?? []) {
      if (d.manager_id) ids.add(String(d.manager_id))
    }
    if (membersProject.department?.manager_id) {
      ids.add(String(membersProject.department.manager_id))
    }
    return ids
  }, [membersProject])

  const filteredCandidates = useMemo(() => {
    const q = memberSearch.trim().toLowerCase()
    return eligibleMembers.filter((u) => {
      if (memberFilter === 'selected' && !memberIds.includes(String(u.id))) return false
      if (memberFilter === 'dept' && !projectDeptIds.has(Number(u.department_id))) return false
      if (!q) return true
      return (
        String(u.name ?? '')
          .toLowerCase()
          .includes(q) ||
        String(u.email ?? '')
          .toLowerCase()
          .includes(q)
      )
    })
  }, [eligibleMembers, memberFilter, memberIds, memberSearch, projectDeptIds])

  function closeMembers() {
    setMembersProject(null)
    setMemberIds([])
    setMemberSearch('')
    setMemberFilter('all')
  }

  function closeForm() {
    setShowForm(false)
    setEditingId(null)
    setForm(emptyForm)
  }

  function openCreate() {
    setEditingId(null)
    setForm({
      ...emptyForm,
      department_ids: !pickDepartments && lockedDeptId ? [lockedDeptId] : [],
    })
    setShowForm(true)
  }

  async function openEdit(project) {
    try {
      const full = await projectsApi.get(project.id)
      setEditingId(full.id)
      const deptIds = (full.departments?.length
        ? full.departments
        : full.department
          ? [full.department]
          : []
      ).map((d) => String(d.id))

      setForm({
        name: full.name ?? '',
        code: full.code ?? '',
        description: full.description ?? '',
        department_ids: !pickDepartments && lockedDeptId ? [lockedDeptId] : deptIds,
        status: full.status || 'actif',
        starts_at: full.starts_at ?? '',
        ends_at: full.ends_at ?? '',
      })
      setShowForm(true)
    } catch (e) {
      toast.error(getErrorMessage(e, 'Impossible de charger le projet.'))
    }
  }

  async function openMembers(project) {
    try {
      const full = await projectsApi.get(project.id)
      setMembersProject(full)
      setMemberIds((full.members ?? []).map((m) => String(m.id)))
    } catch (e) {
      toast.error(getErrorMessage(e, 'Impossible de charger les membres.'))
    }
  }

  function toggleDept(id) {
    const sid = String(id)
    setForm((prev) => ({
      ...prev,
      department_ids: prev.department_ids.includes(sid)
        ? prev.department_ids.filter((x) => x !== sid)
        : [...prev.department_ids, sid],
    }))
  }

  function toggleMember(id) {
    const sid = String(id)
    if (lockedMemberIds.has(sid) && memberIds.includes(sid)) return
    setMemberIds((prev) =>
      prev.includes(sid) ? prev.filter((x) => x !== sid) : [...prev, sid],
    )
  }

  const columns = [
    {
      key: 'name',
      header: 'Projet',
      cell: (p) => {
        const folderId = p.root_folder_id ?? p.root_folder?.id
        const title = folderId ? (
          <Link to={`/explorer?folder=${folderId}`} className="font-medium hover:text-primary">
            {p.name}
          </Link>
        ) : (
          <span className="font-medium">{p.name}</span>
        )
        return (
          <div>
            {title}
            {p.description ? (
              <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{p.description}</p>
            ) : null}
          </div>
        )
      },
    },
    {
      key: 'code',
      header: 'Code',
      cell: (p) => <span className="text-muted-foreground">{p.code || '—'}</span>,
    },
    {
      key: 'depts',
      header: 'Départements',
      cell: (p) => {
        const depts = p.departments?.length ? p.departments : p.department ? [p.department] : []
        return depts.length ? (
          <div className="flex flex-wrap gap-1">
            {depts.map((d) => (
              <Badge key={d.id}>{d.name}</Badge>
            ))}
          </div>
        ) : (
          <span className="text-muted-foreground">—</span>
        )
      },
    },
    {
      key: 'dates',
      header: 'Période',
      cell: (p) => (
        <span className="text-xs text-muted-foreground">
          {p.starts_at || p.ends_at
            ? `${p.starts_at ? formatDate(p.starts_at) : '…'} → ${p.ends_at ? formatDate(p.ends_at) : '…'}`
            : '—'}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Statut',
      cell: (p) => <Badge>{statusLabel(p.status)}</Badge>,
    },
    {
      key: 'members',
      header: 'Membres',
      cell: (p) => p.members_count ?? 0,
    },
    ...(canManage
      ? [
          {
            key: 'actions',
            header: 'Actions',
            className: 'w-[1%] whitespace-nowrap',
            cell: (p) => (
              <div className="flex gap-1">
                <Button variant="ghost" size="sm" title="Modifier" onClick={() => openEdit(p)}>
                  <Pencil className="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  title="Gérer les membres"
                  onClick={() => openMembers(p)}
                >
                  <UserPlus className="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  title="Supprimer"
                  onClick={async () => {
                    const ok = await confirm({
                      title: 'Supprimer le projet',
                      description: `Supprimer « ${p.name} » ?`,
                      confirmLabel: 'Supprimer',
                    })
                    if (ok) removeProject.mutate(p.id)
                  }}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            ),
          },
        ]
      : []),
  ]

  return (
    <RequirePermission anyOf={['projects.view', 'projects.manage']}>
      <PageHeader
        title="Projets"
        description={
          canManage
            ? 'Création et suivi des projets documentaires.'
            : 'Projets auxquels vous participez.'
        }
        actions={
          canManage ? (
            <Button size="sm" onClick={openCreate}>
              <Plus className="h-4 w-4" />
              Nouveau
            </Button>
          ) : null
        }
      />

      <Input
        className="mb-4 max-w-sm"
        placeholder="Rechercher…"
        value={search}
        onChange={(e) => {
          setSearch(e.target.value)
          setPage(1)
        }}
      />

      <DataTable
        columns={columns}
        rows={projects}
        loading={isLoading}
        emptyTitle="Aucun projet"
        meta={meta}
        onPageChange={setPage}
      />

      <Modal
        open={showForm}
        onClose={closeForm}
        title={editingId ? 'Modifier le projet' : 'Nouveau projet'}
        description="Un dossier projet dédié est créé automatiquement (hors explorateur racine)."
        size="lg"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeForm}>
              Annuler
            </Button>
            <Button type="submit" form="project-form" disabled={saving}>
              {editingId ? 'Enregistrer' : 'Créer'}
            </Button>
          </>
        }
      >
        <form
          id="project-form"
          className="grid gap-3 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (pickDepartments && !form.department_ids.length) {
              toast.error('Sélectionnez au moins un département.')
              return
            }
            if (!pickDepartments && !lockedDeptId) {
              toast.error('Votre compte n’est rattaché à aucun département.')
              return
            }
            if (editingId) updateProject.mutate()
            else createProject.mutate()
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
            <Label htmlFor="p-status">Statut</Label>
            <select
              id="p-status"
              className={selectClass}
              value={form.status}
              onChange={(e) => setForm({ ...form, status: e.target.value })}
            >
              {STATUSES.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <Label htmlFor="p-start">Début</Label>
              <Input
                id="p-start"
                type="date"
                value={form.starts_at}
                onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
              />
            </div>
            <div>
              <Label htmlFor="p-end">Fin</Label>
              <Input
                id="p-end"
                type="date"
                value={form.ends_at}
                onChange={(e) => setForm({ ...form, ends_at: e.target.value })}
              />
            </div>
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="p-desc">Description</Label>
            <Input
              id="p-desc"
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Départements</Label>
            {pickDepartments ? (
              <div className="mt-2 flex max-h-36 flex-wrap gap-2 overflow-y-auto rounded-lg border border-border p-3">
                {departments.map((d) => (
                  <label
                    key={d.id}
                    className="flex cursor-pointer items-center gap-2 rounded-lg border border-border px-3 py-1.5 text-xs"
                  >
                    <input
                      type="checkbox"
                      checked={form.department_ids.includes(String(d.id))}
                      onChange={() => toggleDept(d.id)}
                    />
                    {d.name}
                  </label>
                ))}
                {!departments.length ? (
                  <p className="text-xs text-muted-foreground">Aucun département</p>
                ) : null}
              </div>
            ) : (
              <p className="mt-2 text-sm text-muted-foreground">
                Associé automatiquement à{' '}
                <span className="font-medium text-foreground">
                  {user?.department?.name ?? 'votre département'}
                </span>
                .
              </p>
            )}
          </div>
        </form>
      </Modal>

      <Modal
        open={Boolean(membersProject)}
        onClose={closeMembers}
        title={membersProject ? `Membres — ${membersProject.name}` : 'Membres'}
        description="Créateur et responsables de département sont toujours inclus. Recherchez pour ajouter d’autres utilisateurs."
        size="lg"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeMembers}>
              Annuler
            </Button>
            <Button
              type="button"
              disabled={syncMembers.isPending}
              onClick={() => syncMembers.mutate()}
            >
              Enregistrer ({memberIds.length})
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div className="flex flex-col gap-2 sm:flex-row">
            <Input
              className="flex-1"
              placeholder="Rechercher un nom ou un e-mail…"
              value={memberSearch}
              onChange={(e) => setMemberSearch(e.target.value)}
            />
            <select
              className={selectClass + ' sm:w-44'}
              value={memberFilter}
              onChange={(e) => setMemberFilter(e.target.value)}
            >
              <option value="all">Tous</option>
              <option value="selected">Sélectionnés</option>
              <option value="dept">Dépts du projet</option>
            </select>
          </div>

          <div className="max-h-80 overflow-y-auto rounded-lg border border-border">
            {filteredCandidates.map((u) => {
              const sid = String(u.id)
              const locked = lockedMemberIds.has(sid)
              const checked = memberIds.includes(sid)
              const inDept = projectDeptIds.has(Number(u.department_id))
              return (
                <label
                  key={u.id}
                  className="flex cursor-pointer items-start gap-3 border-b border-border px-3 py-2.5 last:border-b-0 hover:bg-muted/40"
                >
                  <input
                    type="checkbox"
                    className="mt-1"
                    checked={checked}
                    disabled={locked}
                    onChange={() => toggleMember(u.id)}
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">{u.name}</span>
                    <span className="block truncate text-xs text-muted-foreground">{u.email}</span>
                  </span>
                  <span className="flex shrink-0 flex-col items-end gap-1">
                    {locked ? <Badge>Auto</Badge> : null}
                    {inDept ? <Badge>Dépt</Badge> : null}
                  </span>
                </label>
              )
            })}
            {!filteredCandidates.length && !candidatesQuery.isLoading ? (
              <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                Aucun utilisateur ne correspond.
              </p>
            ) : null}
          </div>
        </div>
      </Modal>
    </RequirePermission>
  )
}

function payloadFromForm(form, { pickDepartments, lockedDeptId }) {
  const department_ids = pickDepartments
    ? form.department_ids.map(Number)
    : lockedDeptId
      ? [Number(lockedDeptId)]
      : []

  return {
    name: form.name,
    code: form.code || undefined,
    description: form.description || undefined,
    department_ids,
    status: form.status,
    starts_at: form.starts_at || null,
    ends_at: form.ends_at || null,
  }
}
