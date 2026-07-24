import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, ChevronDown, ChevronUp, Shield } from 'lucide-react'
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
import { unwrapList } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import { queryKeys } from '@/lib/queryClient'
import { permissionsApi, rolesApi } from '@/modules/roles/api'

export default function RolesPage() {
  const queryClient = useQueryClient()
  const [expandedId, setExpandedId] = useState(null)
  const [selectedPerms, setSelectedPerms] = useState({})
  const [newRole, setNewRole] = useState({ name: '', description: '' })
  const [showCreate, setShowCreate] = useState(false)

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
      queryClient.invalidateQueries({ queryKey: queryKeys.roles })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const createRole = useMutation({
    mutationFn: () => rolesApi.create(newRole),
    onSuccess: (data) => {
      toast.success(data.message)
      setNewRole({ name: '', description: '' })
      setShowCreate(false)
      queryClient.invalidateQueries({ queryKey: queryKeys.roles })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const roles = unwrapList(rolesQuery.data)
  const permissions = unwrapList(permissionsQuery.data)

  const byModule = permissions.reduce((acc, p) => {
    const mod = p.module ?? 'autre'
    if (!acc[mod]) acc[mod] = []
    acc[mod].push(p)
    return acc
  }, {})

  function openRole(role) {
    if (expandedId === role.id) {
      setExpandedId(null)
      return
    }
    setExpandedId(role.id)
    setSelectedPerms({
      ...selectedPerms,
      [role.id]: (role.permissions ?? []).map((p) => p.id),
    })
  }

  function togglePerm(roleId, permId) {
    setSelectedPerms((prev) => {
      const current = prev[roleId] ?? []
      const next = current.includes(permId)
        ? current.filter((id) => id !== permId)
        : [...current, permId]
      return { ...prev, [roleId]: next }
    })
  }

  if (rolesQuery.isLoading || permissionsQuery.isLoading) {
    return <LoadingScreen />
  }

  return (
    <RequirePermission permission="roles.manage">
      <PageHeader
        title="Rôles & permissions"
        description="RBAC synchronisé via PUT /roles/{id}/permissions."
        actions={
          <Button size="sm" onClick={() => setShowCreate((v) => !v)}>
            <Shield className="h-4 w-4" />
            Nouveau rôle
          </Button>
        }
      />

      {showCreate ? (
        <Card className="mb-6">
          <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault()
              createRole.mutate()
            }}
          >
            <div>
              <Label htmlFor="r-name">Nom</Label>
              <Input
                id="r-name"
                required
                value={newRole.name}
                onChange={(e) => setNewRole({ ...newRole, name: e.target.value })}
              />
            </div>
            <div>
              <Label htmlFor="r-desc">Description</Label>
              <Input
                id="r-desc"
                value={newRole.description}
                onChange={(e) => setNewRole({ ...newRole, description: e.target.value })}
              />
            </div>
            <div className="flex gap-2 sm:col-span-2">
              <Button type="submit" disabled={createRole.isPending}>
                Créer
              </Button>
              <Button type="button" variant="secondary" onClick={() => setShowCreate(false)}>
                Annuler
              </Button>
            </div>
          </form>
        </Card>
      ) : null}

      {roles.length === 0 ? (
        <EmptyState title="Aucun rôle" />
      ) : (
        <div className="space-y-3">
          {roles.map((role) => {
            const open = expandedId === role.id
            const checked = selectedPerms[role.id] ?? []

            return (
              <Card key={role.id} className="p-0 overflow-hidden">
                <button
                  type="button"
                  className="flex w-full items-center justify-between px-5 py-4 text-left hover:bg-muted/40"
                  onClick={() => openRole(role)}
                >
                  <div>
                    <p className="font-medium">{role.name}</p>
                    <p className="text-xs text-muted-foreground">
                      {role.slug} · {(role.permissions ?? []).length} permission(s) ·{' '}
                      {role.users_count ?? 0} utilisateur(s)
                    </p>
                  </div>
                  {open ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                </button>

                {open ? (
                  <div className="border-t border-border px-5 py-4">
                    {role.description ? (
                      <p className="mb-4 text-sm text-muted-foreground">{role.description}</p>
                    ) : null}
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                      {Object.entries(byModule).map(([mod, perms]) => (
                        <div key={mod}>
                          <p className="mb-2 text-xs font-semibold uppercase text-muted-foreground">
                            {mod}
                          </p>
                          <ul className="space-y-1">
                            {perms.map((p) => (
                              <li key={p.id}>
                                <label className="flex cursor-pointer items-center gap-2 text-xs">
                                  <input
                                    type="checkbox"
                                    checked={checked.includes(p.id)}
                                    onChange={() => togglePerm(role.id, p.id)}
                                  />
                                  {p.name}
                                </label>
                              </li>
                            ))}
                          </ul>
                        </div>
                      ))}
                    </div>
                    <Button
                      className="mt-4"
                      size="sm"
                      onClick={() =>
                        syncPermissions.mutate({
                          roleId: role.id,
                          permissionIds: checked,
                        })
                      }
                      disabled={syncPermissions.isPending}
                    >
                      <Check className="h-4 w-4" />
                      Enregistrer les permissions
                    </Button>
                  </div>
                ) : null}
              </Card>
            )
          })}
        </div>
      )}
    </RequirePermission>
  )
}
