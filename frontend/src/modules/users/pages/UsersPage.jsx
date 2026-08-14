import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Pencil, Trash2, UserPlus } from 'lucide-react'
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
import { unwrapList, unwrapPaginated, PAGE_SIZE } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { usersApi } from '@/modules/users/api'
import { departmentsApi } from '@/modules/departments/api'
import { rolesApi } from '@/modules/roles/api'

const emptyForm = {
  name: '',
  email: '',
  department_id: '',
  role_ids: [],
  is_active: true,
}

const ROLES_WITHOUT_DEPARTMENT = ['invite', 'administrateur', 'direction', 'chef_projet']

const selectClass = 'h-11 w-full rounded-lg border border-border bg-background px-3 text-sm'

function hasNoDepartmentRole(roles, roleIds) {
  const selected = new Set((roleIds ?? []).map(String))
  return roles.some((r) => selected.has(String(r.id)) && ROLES_WITHOUT_DEPARTMENT.includes(r.slug))
}

export default function UsersPage() {
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [search, setSearch] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(emptyForm)

  const listParams = {
    search: search || undefined,
    role: roleFilter || undefined,
    is_active: statusFilter === '' ? undefined : statusFilter,
    per_page: PAGE_SIZE,
    page,
  }

  const usersQuery = useQuery({
    queryKey: queryKeys.users(listParams),
    queryFn: () => usersApi.list(listParams),
  })

  const rolesQuery = useQuery({
    queryKey: queryKeys.roles,
    queryFn: rolesApi.list,
  })

  const departmentsQuery = useQuery({
    queryKey: queryKeys.departments({ per_page: 100 }),
    queryFn: () => departmentsApi.list({ per_page: 100 }),
  })

  const createUser = useMutation({
    mutationFn: (opts = {}) =>
      usersApi.create({
        name: form.name,
        email: form.email,
        department_id: hasNoDepartmentRole(roles, form.role_ids)
          ? null
          : form.department_id
            ? Number(form.department_id)
            : null,
        role_ids: form.role_ids.map(Number),
        is_active: true,
        replace_department_manager: opts.replace_department_manager || undefined,
      }),
    onSuccess: (data) => {
      toast.success(data.message)
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['users'] })
      queryClient.invalidateQueries({ queryKey: ['departments'] })
      queryClient.invalidateQueries({ queryKey: ['projects'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const updateUser = useMutation({
    mutationFn: (opts = {}) =>
      usersApi.update(editingId, {
        name: form.name,
        email: form.email,
        department_id: hasNoDepartmentRole(roles, form.role_ids)
          ? null
          : form.department_id
            ? Number(form.department_id)
            : null,
        role_ids: form.role_ids.map(Number),
        is_active: form.is_active,
        replace_department_manager: opts.replace_department_manager || undefined,
      }),
    onSuccess: (data) => {
      toast.success(
        form.is_active
          ? (data.message ?? 'Utilisateur mis à jour.')
          : 'Compte désactivé — sessions fermées.',
      )
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['users'] })
      queryClient.invalidateQueries({ queryKey: ['departments'] })
      queryClient.invalidateQueries({ queryKey: ['projects'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const resetPassword = useMutation({
    mutationFn: (id) => usersApi.resetPassword(id),
    onSuccess: (data) => toast.success(data.message),
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const deactivateUser = useMutation({
    mutationFn: (id) => usersApi.remove(id),
    onSuccess: (data) => {
      toast.success(data.message)
      queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const { data: usersPage, meta } = unwrapPaginated(usersQuery.data)
  const roles = unwrapList(rolesQuery.data)
  const departments = unwrapList(departmentsQuery.data)
  const saving = createUser.isPending || updateUser.isPending
  const withoutDepartment = hasNoDepartmentRole(roles, form.role_ids)

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

  function openEdit(user) {
    const roleIds = (user.roles ?? []).map((r) => String(r.id))
    const hideDept = hasNoDepartmentRole(user.roles ?? roles, roleIds)

    setEditingId(user.id)
    setForm({
      name: user.name ?? '',
      email: user.email ?? '',
      department_id: hideDept
        ? ''
        : user.department_id != null
          ? String(user.department_id)
          : user.department?.id != null
            ? String(user.department.id)
            : '',
      role_ids: roleIds,
      is_active: Boolean(user.is_active),
    })
    setShowForm(true)
  }

  function toggleRole(roleId) {
    const sid = String(roleId)
    const invite = roles.find((r) => r.slug === 'invite')
    const inviteId = invite ? String(invite.id) : null

    setForm((prev) => {
      const has = prev.role_ids.includes(sid)
      if (has) {
        return { ...prev, role_ids: prev.role_ids.filter((id) => id !== sid) }
      }
      // Invité exclusif + sans département
      if (inviteId && sid === inviteId) {
        return { ...prev, role_ids: [inviteId], department_id: '' }
      }
      const nextRoles = [...prev.role_ids.filter((id) => id !== inviteId), sid]
      const picked = roles.find((r) => String(r.id) === sid)
      return {
        ...prev,
        role_ids: nextRoles,
        department_id: ROLES_WITHOUT_DEPARTMENT.includes(picked?.slug)
          ? ''
          : prev.department_id,
      }
    })
  }

  async function handleSubmit(e) {
    e.preventDefault()
    const respRole = roles.find((r) => r.slug === 'responsable_departement')
    const wantsResp = respRole && form.role_ids.includes(String(respRole.id))
    let replace = false

    if (wantsResp) {
      if (!form.department_id) {
        toast.error('Sélectionnez un département pour le rôle responsable.')
        return
      }
      const dept = departments.find((d) => String(d.id) === String(form.department_id))
      const currentId = dept?.manager_id ?? dept?.manager?.id
      if (currentId && String(currentId) !== String(editingId ?? '')) {
        const ok = await confirm({
          title: 'Remplacer le responsable ?',
          description: `${dept?.manager?.name ?? 'Le responsable actuel'} perdra ce rôle sur « ${dept?.name ?? 'ce département'} » et redeviendra collaborateur s’il n’a plus d’autre rôle. ${form.name} prendra sa place.`,
          confirmLabel: 'Remplacer',
          tone: 'danger',
        })
        if (!ok) return
        replace = true
      }
    }

    const opts = { replace_department_manager: replace }
    if (editingId) updateUser.mutate(opts)
    else createUser.mutate(opts)
  }

  const columns = [
    { key: 'name', header: 'Nom', cell: (u) => <span className="font-medium">{u.name}</span> },
    { key: 'email', header: 'Email', cell: (u) => <span className="text-muted-foreground">{u.email}</span> },
    {
      key: 'roles',
      header: 'Rôles',
      cell: (u) => (
        <div className="flex flex-wrap gap-1">
          {(u.roles ?? []).map((r) => (
            <Badge key={r.id}>{r.name}</Badge>
          ))}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Statut',
      cell: (u) => (
        <Badge tone={u.is_active ? 'success' : 'danger'}>{u.is_active ? 'Actif' : 'Inactif'}</Badge>
      ),
    },
    {
      key: 'created',
      header: 'Créé',
      cell: (u) => <span className="text-xs text-muted-foreground">{formatDate(u.created_at)}</span>,
    },
    {
      key: 'actions',
      header: 'Actions',
      className: 'w-[1%] whitespace-nowrap',
      cell: (u) => (
        <div className="flex gap-1">
          <Button variant="ghost" size="sm" title="Modifier" onClick={() => openEdit(u)}>
            <Pencil className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            title="Réinitialiser le mot de passe"
            onClick={() => resetPassword.mutate(u.id)}
          >
            <KeyRound className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            title="Supprimer"
            onClick={async () => {
              const ok = await confirm({
                title: 'Supprimer l’utilisateur',
                description: `Supprimer définitivement ${u.name} ? Son accès sera révoqué.`,
                confirmLabel: 'Supprimer',
              })
              if (ok) deactivateUser.mutate(u.id)
            }}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      ),
    },
  ]

  return (
    <RequirePermission permission="users.view">
      <PageHeader
        title="Utilisateurs"
        description="Création et gestion des comptes utilisateurs."
        actions={
          <Button size="sm" onClick={openCreate}>
            <UserPlus className="h-4 w-4" />
            Nouvel utilisateur
          </Button>
        }
      />

      <div className="mb-4 rounded-xl border border-border bg-background p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_auto] lg:items-end">
          <div>
            <Label htmlFor="f-search">Recherche</Label>
            <Input
              id="f-search"
              placeholder="Nom ou email…"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
            />
          </div>
          <div>
            <Label htmlFor="f-role">Rôle</Label>
            <select
              id="f-role"
              className={selectClass}
              value={roleFilter}
              onChange={(e) => {
                setRoleFilter(e.target.value)
                setPage(1)
              }}
            >
              <option value="">Tous</option>
              {roles.map((role) => (
                <option key={role.id} value={role.slug}>
                  {role.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label htmlFor="f-status">Statut</Label>
            <select
              id="f-status"
              className={selectClass}
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value)
                setPage(1)
              }}
            >
              <option value="">Tous</option>
              <option value="1">Actifs</option>
              <option value="0">Inactifs</option>
            </select>
          </div>
          <div className="flex sm:col-span-2 lg:col-span-1">
            <Button
              type="button"
              variant="secondary"
              className="w-full lg:w-auto"
              disabled={!roleFilter && !statusFilter && !search}
              onClick={() => {
                setSearch('')
                setRoleFilter('')
                setStatusFilter('')
                setPage(1)
              }}
            >
              Réinitialiser
            </Button>
          </div>
        </div>
      </div>

      <DataTable
        columns={columns}
        rows={usersPage}
        loading={usersQuery.isLoading}
        emptyTitle="Aucun utilisateur"
        meta={meta}
        onPageChange={setPage}
      />

      <Modal
        open={showForm}
        onClose={closeForm}
        title={editingId ? 'Modifier l’utilisateur' : 'Nouvel utilisateur'}
        description={
          editingId
            ? 'Mettez à jour le compte et ses rôles.'
            : 'Un mot de passe temporaire sera envoyé par e-mail.'
        }
        size="lg"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeForm}>
              Annuler
            </Button>
            <Button type="submit" form="user-form" disabled={saving}>
              {editingId ? 'Enregistrer' : 'Créer'}
            </Button>
          </>
        }
      >
        <form
          id="user-form"
          className="grid gap-4 sm:grid-cols-2"
          onSubmit={handleSubmit}
        >
          <div>
            <Label htmlFor="u-name">Nom</Label>
            <Input
              id="u-name"
              required
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
          </div>
          <div>
            <Label htmlFor="u-email">Email</Label>
            <Input
              id="u-email"
              type="email"
              required
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
            />
          </div>
          {!withoutDepartment ? (
            <div>
              <Label htmlFor="u-dept">Département</Label>
              <select
                id="u-dept"
                className={selectClass}
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
          ) : null}
          {editingId ? (
            <div className={`flex flex-col justify-end ${withoutDepartment ? 'sm:col-span-2' : ''}`}>
              <label className="flex cursor-pointer items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                />
                Compte actif
              </label>
              {!form.is_active ? (
                <p className="mt-1 text-xs text-muted-foreground">
                  La désactivation ferme immédiatement toutes ses sessions.
                </p>
              ) : null}
            </div>
          ) : null}
          <div className="sm:col-span-2">
            <Label>Rôles</Label>
            <p className="mb-2 text-xs text-muted-foreground">
              Le rôle Invité est exclusif. Administrateur, chef de projet, direction et invité
              ne sont pas rattachés à un département.
            </p>
            <div className="mt-2 flex flex-wrap gap-2">
              {roles.map((role) => (
                <label
                  key={role.id}
                  className="flex cursor-pointer items-center gap-2 rounded-lg border border-border px-3 py-1.5 text-xs"
                >
                  <input
                    type="checkbox"
                    checked={form.role_ids.includes(String(role.id))}
                    onChange={() => toggleRole(role.id)}
                  />
                  {role.name}
                </label>
              ))}
            </div>
          </div>
        </form>
      </Modal>
    </RequirePermission>
  )
}
