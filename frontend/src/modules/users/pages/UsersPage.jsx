import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Pencil, Plus, Trash2, UserPlus } from 'lucide-react'
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
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
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

export default function UsersPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(emptyForm)

  const usersQuery = useQuery({
    queryKey: queryKeys.users({ search }),
    queryFn: () => usersApi.list({ search: search || undefined, per_page: 20 }),
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
    mutationFn: () =>
      usersApi.create({
        name: form.name,
        email: form.email,
        department_id: form.department_id ? Number(form.department_id) : null,
        role_ids: form.role_ids.map(Number),
        is_active: true,
      }),
    onSuccess: (data) => {
      toast.success(data.message)
      if (data.temporary_password) {
        toast.info(`Mot de passe temporaire : ${data.temporary_password}`, { duration: 15000 })
      }
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const updateUser = useMutation({
    mutationFn: () =>
      usersApi.update(editingId, {
        name: form.name,
        email: form.email,
        department_id: form.department_id ? Number(form.department_id) : null,
        role_ids: form.role_ids.map(Number),
        is_active: form.is_active,
      }),
    onSuccess: (data) => {
      toast.success(data.message ?? 'Utilisateur mis à jour.')
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const resetPassword = useMutation({
    mutationFn: (id) => usersApi.resetPassword(id),
    onSuccess: (data) => {
      toast.success(data.message)
      if (data.temporary_password) {
        toast.info(`Nouveau mot de passe : ${data.temporary_password}`, { duration: 15000 })
      }
    },
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
    setEditingId(user.id)
    setForm({
      name: user.name ?? '',
      email: user.email ?? '',
      department_id: user.department_id != null ? String(user.department_id) : user.department?.id != null ? String(user.department.id) : '',
      role_ids: (user.roles ?? []).map((r) => String(r.id)),
      is_active: Boolean(user.is_active),
    })
    setShowForm(true)
  }

  function toggleRole(roleId) {
    setForm((prev) => ({
      ...prev,
      role_ids: prev.role_ids.includes(String(roleId))
        ? prev.role_ids.filter((id) => id !== String(roleId))
        : [...prev.role_ids, String(roleId)],
    }))
  }

  return (
    <RequirePermission permission="users.view">
      <PageHeader
        title="Utilisateurs"
        description="Création et gestion des comptes (API /users)."
        actions={
          <Button size="sm" onClick={openCreate}>
            <UserPlus className="h-4 w-4" />
            Nouvel utilisateur
          </Button>
        }
      />

      <div className="mb-4 flex gap-2">
        <Input
          placeholder="Rechercher par nom ou email…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="max-w-sm"
        />
      </div>

      {showForm ? (
        <Card className="mb-6">
          <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault()
              if (editingId) updateUser.mutate()
              else createUser.mutate()
            }}
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
            <div>
              <Label htmlFor="u-dept">Département</Label>
              <select
                id="u-dept"
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
            {editingId ? (
              <div className="flex items-end">
                <label className="flex cursor-pointer items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={form.is_active}
                    onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                  />
                  Compte actif
                </label>
              </div>
            ) : null}
            <div className="sm:col-span-2">
              <Label>Rôles</Label>
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
            <div className="flex gap-2 sm:col-span-2">
              <Button type="submit" disabled={saving}>
                <Plus className="h-4 w-4" />
                {editingId ? 'Enregistrer' : 'Créer'}
              </Button>
              <Button type="button" variant="secondary" onClick={closeForm}>
                Annuler
              </Button>
            </div>
          </form>
        </Card>
      ) : null}

      {usersQuery.isLoading ? (
        <LoadingScreen />
      ) : usersPage.length === 0 ? (
        <EmptyState title="Aucun utilisateur" />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-border bg-background">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-border bg-muted/50 text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Nom</th>
                <th className="px-4 py-2 font-medium">Email</th>
                <th className="px-4 py-2 font-medium">Rôles</th>
                <th className="px-4 py-2 font-medium">Statut</th>
                <th className="px-4 py-2 font-medium">Créé</th>
                <th className="px-4 py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {usersPage.map((user) => (
                <tr key={user.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-3 font-medium">{user.name}</td>
                  <td className="px-4 py-3 text-muted-foreground">{user.email}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {(user.roles ?? []).map((r) => (
                        <Badge key={r.id}>{r.name}</Badge>
                      ))}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={user.is_active ? 'success' : 'danger'}>
                      {user.is_active ? 'Actif' : 'Inactif'}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-xs text-muted-foreground">
                    {formatDate(user.created_at)}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex gap-1">
                      <Button
                        variant="ghost"
                        size="sm"
                        title="Modifier"
                        onClick={() => openEdit(user)}
                      >
                        <Pencil className="h-4 w-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        title="Réinitialiser le mot de passe"
                        onClick={() => resetPassword.mutate(user.id)}
                      >
                        <KeyRound className="h-4 w-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        title="Mettre en corbeille"
                        onClick={() => {
                          if (window.confirm(`Supprimer ${user.name} ?`)) {
                            deactivateUser.mutate(user.id)
                          }
                        }}
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {meta ? (
            <p className="border-t border-border px-4 py-2 text-xs text-muted-foreground">
              {meta.total} utilisateur(s)
            </p>
          ) : null}
        </div>
      )}
    </RequirePermission>
  )
}
