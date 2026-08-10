import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useConfirm } from '@/components/ConfirmDialog'
import RequirePermission from '@/components/RequirePermission'
import Button from '@/components/ui/Button'
import DataTable from '@/components/ui/DataTable'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import Modal from '@/components/ui/Modal'
import PageHeader from '@/components/ui/PageHeader'
import { unwrapList, unwrapPaginated, PAGE_SIZE } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { departmentsApi } from '@/modules/departments/api'
import { usersApi } from '@/modules/users/api'

const emptyForm = { name: '', code: '', description: '', manager_id: '' }
const selectClass = 'h-11 w-full rounded-lg border border-border bg-background px-3 text-sm'

export default function DepartmentsPage() {
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(emptyForm)

  const listParams = {
    search: search || undefined,
    per_page: PAGE_SIZE,
    page,
  }

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.departments(listParams),
    queryFn: () => departmentsApi.list(listParams),
  })

  const usersQuery = useQuery({
    queryKey: queryKeys.users({ per_page: 200, is_active: '1' }),
    queryFn: () => usersApi.list({ per_page: 200, is_active: 1 }),
    enabled: showForm,
  })

  const createDept = useMutation({
    mutationFn: () =>
      departmentsApi.create({
        name: form.name,
        code: form.code || undefined,
        description: form.description || undefined,
        manager_id: form.manager_id ? Number(form.manager_id) : null,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['departments'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const updateDept = useMutation({
    mutationFn: () =>
      departmentsApi.update(editingId, {
        name: form.name,
        code: form.code || undefined,
        description: form.description || undefined,
        manager_id: form.manager_id ? Number(form.manager_id) : null,
      }),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Département mis à jour.')
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['departments'] })
      queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeDept = useMutation({
    mutationFn: (id) => departmentsApi.remove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['departments'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const { data: departments, meta } = unwrapPaginated(data)
  const users = unwrapList(usersQuery.data)
  const saving = createDept.isPending || updateDept.isPending

  function closeForm() {
    setShowForm(false)
    setEditingId(null)
    setForm(emptyForm)
  }

  function openCreate() {
    setEditingId(null)
    setForm(emptyForm)
    setShowForm(true)
  }

  function openEdit(dept) {
    setEditingId(dept.id)
    setForm({
      name: dept.name ?? '',
      code: dept.code ?? '',
      description: dept.description ?? '',
      manager_id:
        dept.manager_id != null
          ? String(dept.manager_id)
          : dept.manager?.id != null
            ? String(dept.manager.id)
            : '',
    })
    setShowForm(true)
  }

  const columns = [
    {
      key: 'name',
      header: 'Nom',
      cell: (d) => (
        <div>
          <p className="font-medium">{d.name}</p>
          {d.description ? (
            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{d.description}</p>
          ) : null}
        </div>
      ),
    },
    {
      key: 'code',
      header: 'Code',
      cell: (d) => <span className="text-muted-foreground">{d.code || '—'}</span>,
    },
    {
      key: 'manager',
      header: 'Responsable',
      cell: (d) => d.manager?.name ?? <span className="text-muted-foreground">—</span>,
    },
    {
      key: 'counts',
      header: 'Effectifs',
      cell: (d) => (
        <span className="text-xs text-muted-foreground">
          {d.users_count ?? 0} user(s) · {d.projects_count ?? 0} projet(s)
        </span>
      ),
    },
    {
      key: 'created',
      header: 'Créé',
      cell: (d) => <span className="text-xs text-muted-foreground">{formatDate(d.created_at)}</span>,
    },
    {
      key: 'actions',
      header: 'Actions',
      className: 'w-[1%] whitespace-nowrap',
      cell: (d) => (
        <div className="flex gap-1">
          <Button variant="ghost" size="sm" title="Modifier" onClick={() => openEdit(d)}>
            <Pencil className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            title="Supprimer"
            onClick={async () => {
              const ok = await confirm({
                title: 'Supprimer le département',
                description: `Supprimer « ${d.name} » ? Cette action archive le département.`,
                confirmLabel: 'Supprimer',
              })
              if (ok) removeDept.mutate(d.id)
            }}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      ),
    },
  ]

  return (
    <RequirePermission permission="departments.manage">
      <PageHeader
        title="Départements"
        description="Structure organisationnelle de l'entreprise."
        actions={
          <Button size="sm" onClick={openCreate}>
            <Plus className="h-4 w-4" />
            Nouveau
          </Button>
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
        rows={departments}
        loading={isLoading}
        emptyTitle="Aucun département"
        meta={meta}
        onPageChange={setPage}
      />

      <Modal
        open={showForm}
        onClose={closeForm}
        title={editingId ? 'Modifier le département' : 'Nouveau département'}
        size="md"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeForm}>
              Annuler
            </Button>
            <Button type="submit" form="dept-form" disabled={saving}>
              {editingId ? 'Enregistrer' : 'Créer'}
            </Button>
          </>
        }
      >
        <form
          id="dept-form"
          className="grid gap-3 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (editingId) updateDept.mutate()
            else createDept.mutate()
          }}
        >
          <div>
            <Label htmlFor="d-name">Nom</Label>
            <Input
              id="d-name"
              required
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
          </div>
          <div>
            <Label htmlFor="d-code">Code</Label>
            <Input
              id="d-code"
              value={form.code}
              onChange={(e) => setForm({ ...form, code: e.target.value })}
            />
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="d-desc">Description</Label>
            <Input
              id="d-desc"
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
            />
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="d-manager">Responsable</Label>
            <select
              id="d-manager"
              className={selectClass}
              value={form.manager_id}
              onChange={(e) => setForm({ ...form, manager_id: e.target.value })}
            >
              <option value="">— Aucun —</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.name} ({u.email})
                </option>
              ))}
            </select>
            {editingId && form.manager_id ? (
              <p className="mt-1.5 text-xs text-muted-foreground">
                Un seul responsable par département. Son département devient automatiquement celui-ci ;
                l’ancien responsable reste membre.
              </p>
            ) : (
              <p className="mt-1.5 text-xs text-muted-foreground">
                Un seul responsable par département — il y est rattaché automatiquement.
              </p>
            )}
          </div>
        </form>
      </Modal>
    </RequirePermission>
  )
}
