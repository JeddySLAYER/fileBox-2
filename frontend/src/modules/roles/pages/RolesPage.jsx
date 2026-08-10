import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Pencil, Shield, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useConfirm } from '@/components/ConfirmDialog'
import RequirePermission from '@/components/RequirePermission'
import Button from '@/components/ui/Button'
import DataTable from '@/components/ui/DataTable'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import Modal from '@/components/ui/Modal'
import PageHeader from '@/components/ui/PageHeader'
import { unwrapList, paginateClient, PAGE_SIZE } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import { queryKeys } from '@/lib/queryClient'
import { permissionsApi, rolesApi } from '@/modules/roles/api'

const emptyRoleForm = { name: '', description: '' }

export default function RolesPage() {
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [roleForm, setRoleForm] = useState(emptyRoleForm)
  const [permsRole, setPermsRole] = useState(null)
  const [selectedPerms, setSelectedPerms] = useState([])

  const rolesQuery = useQuery({
    queryKey: queryKeys.roles,
    queryFn: rolesApi.list,
  })

  const permissionsQuery = useQuery({
    queryKey: queryKeys.permissions,
    queryFn: () => permissionsApi.list(),
  })

  const syncPermissions = useMutation({
    mutationFn: ({ roleId, permissionIds }) => rolesApi.syncPermissions(roleId, permissionIds),
    onSuccess: (data) => {
      toast.success(data.message)
      setPermsRole(null)
      queryClient.invalidateQueries({ queryKey: queryKeys.roles })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const createRole = useMutation({
    mutationFn: () => rolesApi.create(roleForm),
    onSuccess: (data) => {
      toast.success(data.message)
      closeForm()
      queryClient.invalidateQueries({ queryKey: queryKeys.roles })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const updateRole = useMutation({
    mutationFn: () =>
      rolesApi.update(editingId, {
        name: roleForm.name,
        description: roleForm.description || null,
      }),
    onSuccess: (data) => {
      toast.success(data.message ?? 'Rôle mis à jour.')
      closeForm()
      queryClient.invalidateQueries({ queryKey: queryKeys.roles })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeRole = useMutation({
    mutationFn: (id) => rolesApi.remove(id),
    onSuccess: (data) => {
      toast.success(data.message ?? 'Rôle supprimé.')
      queryClient.invalidateQueries({ queryKey: queryKeys.roles })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const roles = unwrapList(rolesQuery.data)
  const permissions = unwrapList(permissionsQuery.data)
  const { data: pageRows, meta } = useMemo(
    () => paginateClient(roles, page, PAGE_SIZE),
    [roles, page],
  )
  const saving = createRole.isPending || updateRole.isPending

  const byModule = useMemo(
    () =>
      permissions.reduce((acc, p) => {
        const mod = p.module ?? 'autre'
        if (!acc[mod]) acc[mod] = []
        acc[mod].push(p)
        return acc
      }, {}),
    [permissions],
  )

  function closeForm() {
    setShowForm(false)
    setEditingId(null)
    setRoleForm(emptyRoleForm)
  }

  function openCreate() {
    setEditingId(null)
    setRoleForm(emptyRoleForm)
    setShowForm(true)
  }

  function openEdit(role) {
    setEditingId(role.id)
    setRoleForm({
      name: role.name ?? '',
      description: role.description ?? '',
    })
    setShowForm(true)
  }

  function openPermissions(role) {
    setPermsRole(role)
    setSelectedPerms((role.permissions ?? []).map((p) => p.id))
  }

  function togglePerm(permId) {
    setSelectedPerms((prev) =>
      prev.includes(permId) ? prev.filter((id) => id !== permId) : [...prev, permId],
    )
  }

  const columns = [
    {
      key: 'name',
      header: 'Rôle',
      cell: (r) => (
        <div>
          <p className="font-medium">{r.name}</p>
          {r.description ? (
            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{r.description}</p>
          ) : null}
        </div>
      ),
    },
    {
      key: 'perms',
      header: 'Permissions',
      cell: (r) => (r.permissions ?? []).length,
    },
    {
      key: 'users',
      header: 'Utilisateurs',
      cell: (r) => r.users_count ?? 0,
    },
    {
      key: 'actions',
      header: 'Actions',
      className: 'w-[1%] whitespace-nowrap',
      cell: (r) => (
        <div className="flex gap-1">
          <Button variant="ghost" size="sm" title="Permissions" onClick={() => openPermissions(r)}>
            <Shield className="h-4 w-4" />
          </Button>
          <Button variant="ghost" size="sm" title="Modifier" onClick={() => openEdit(r)}>
            <Pencil className="h-4 w-4" />
          </Button>
          {!r.is_protected ? (
            <Button
              variant="ghost"
              size="sm"
              title="Supprimer"
              onClick={async () => {
                const ok = await confirm({
                  title: 'Supprimer le rôle',
                  description: `Supprimer « ${r.name} » ? Impossible s’il est encore attribué.`,
                  confirmLabel: 'Supprimer',
                })
                if (ok) removeRole.mutate(r.id)
              }}
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          ) : null}
        </div>
      ),
    },
  ]

  return (
    <RequirePermission permission="roles.manage">
      <PageHeader
        title="Rôles & permissions"
        description="Définition des rôles et des droits d'accès."
        actions={
          <Button size="sm" onClick={openCreate}>
            <Shield className="h-4 w-4" />
            Nouveau rôle
          </Button>
        }
      />

      <DataTable
        columns={columns}
        rows={pageRows}
        loading={rolesQuery.isLoading || permissionsQuery.isLoading}
        emptyTitle="Aucun rôle"
        meta={meta}
        onPageChange={setPage}
      />

      <Modal
        open={showForm}
        onClose={closeForm}
        title={editingId ? 'Modifier le rôle' : 'Nouveau rôle'}
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeForm}>
              Annuler
            </Button>
            <Button type="submit" form="role-form" disabled={saving}>
              {editingId ? 'Enregistrer' : 'Créer'}
            </Button>
          </>
        }
      >
        <form
          id="role-form"
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault()
            if (editingId) updateRole.mutate()
            else createRole.mutate()
          }}
        >
          <div>
            <Label htmlFor="r-name">Nom</Label>
            <Input
              id="r-name"
              required
              value={roleForm.name}
              onChange={(e) => setRoleForm({ ...roleForm, name: e.target.value })}
            />
          </div>
          <div>
            <Label htmlFor="r-desc">Description</Label>
            <Input
              id="r-desc"
              value={roleForm.description}
              onChange={(e) => setRoleForm({ ...roleForm, description: e.target.value })}
            />
          </div>
        </form>
      </Modal>

      <Modal
        open={Boolean(permsRole)}
        onClose={() => setPermsRole(null)}
        title={`Permissions — ${permsRole?.name ?? ''}`}
        description="Cochez les droits associés à ce rôle."
        size="xl"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={() => setPermsRole(null)}>
              Annuler
            </Button>
            <Button
              type="button"
              disabled={syncPermissions.isPending}
              onClick={() =>
                syncPermissions.mutate({
                  roleId: permsRole.id,
                  permissionIds: selectedPerms,
                })
              }
            >
              <Check className="h-4 w-4" />
              Enregistrer
            </Button>
          </>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Object.entries(byModule).map(([mod, perms]) => (
            <div key={mod}>
              <p className="mb-2 text-xs font-semibold uppercase text-muted-foreground">{mod}</p>
              <ul className="space-y-1">
                {perms.map((p) => (
                  <li key={p.id}>
                    <label className="flex cursor-pointer items-center gap-2 text-xs">
                      <input
                        type="checkbox"
                        checked={selectedPerms.includes(p.id)}
                        onChange={() => togglePerm(p.id)}
                      />
                      {p.name}
                    </label>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      </Modal>
    </RequirePermission>
  )
}
