import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Share2, Trash2, UserPlus } from 'lucide-react'
import { toast } from 'sonner'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { canAny } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { ACCESS_ABILITIES, accessesApi } from '@/modules/access/api'
import { usersApi } from '@/modules/users/api'
import { useAuthStore } from '@/stores/authStore'

export default function DocumentAccessPanel({ documentId }) {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({
    user_id: '',
    abilities: ['view', 'download'],
    ends_at: '',
  })

  const canShare = canAny(user, ['documents.share', 'accesses.manage'])

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.documentAccesses(documentId),
    queryFn: () => accessesApi.listForDocument(documentId),
    enabled: canShare,
  })

  const usersQuery = useQuery({
    queryKey: queryKeys.users({ per_page: 100 }),
    queryFn: () => usersApi.list({ per_page: 100 }),
    enabled: showForm && canShare,
  })

  const grantAccess = useMutation({
    mutationFn: () =>
      accessesApi.grantDocument(documentId, {
        user_id: Number(form.user_id),
        abilities: form.abilities,
        ends_at: form.ends_at ? new Date(form.ends_at).toISOString() : null,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      setShowForm(false)
      setForm({ user_id: '', abilities: ['view', 'download'], ends_at: '' })
      queryClient.invalidateQueries({ queryKey: queryKeys.documentAccesses(documentId) })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const revokeAccess = useMutation({
    mutationFn: (accessId) => accessesApi.revoke(accessId),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.documentAccesses(documentId) })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const accesses = unwrapList(data)
  const users = unwrapPaginated(usersQuery.data).data.filter((u) => u.id !== user?.id)

  function toggleAbility(ability) {
    setForm((prev) => ({
      ...prev,
      abilities: prev.abilities.includes(ability)
        ? prev.abilities.filter((a) => a !== ability)
        : [...prev.abilities, ability],
    }))
  }

  if (!canShare) {
    return (
      <p className="text-sm text-muted-foreground">
        Vous n&apos;avez pas la permission de gérer les accès sur ce document.
      </p>
    )
  }

  if (isLoading) return <LoadingScreen label="Accès…" />

  return (
    <div>
      <div className="mb-4 flex justify-end">
        <Button size="sm" variant="secondary" onClick={() => setShowForm((v) => !v)}>
          <UserPlus className="h-4 w-4" />
          Partager
        </Button>
      </div>

      {showForm ? (
        <form
          className="mb-4 space-y-3 rounded-lg border border-border p-4"
          onSubmit={(e) => {
            e.preventDefault()
            grantAccess.mutate()
          }}
        >
          <div>
            <Label>Utilisateur</Label>
            <select
              required
              className="mt-1 h-11 w-full rounded-lg border border-border bg-background px-3 text-sm"
              value={form.user_id}
              onChange={(e) => setForm({ ...form, user_id: e.target.value })}
            >
              <option value="">— Choisir —</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.name} ({u.email})
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label>Capacités</Label>
            <div className="mt-2 flex flex-wrap gap-2">
              {ACCESS_ABILITIES.map((a) => (
                <label
                  key={a}
                  className="flex cursor-pointer items-center gap-1 rounded border border-border px-2 py-1 text-xs"
                >
                  <input
                    type="checkbox"
                    checked={form.abilities.includes(a)}
                    onChange={() => toggleAbility(a)}
                  />
                  {a}
                </label>
              ))}
            </div>
          </div>
          <div>
            <Label htmlFor="ends">Expire le (optionnel)</Label>
            <Input
              id="ends"
              type="datetime-local"
              value={form.ends_at}
              onChange={(e) => setForm({ ...form, ends_at: e.target.value })}
            />
          </div>
          <div className="flex gap-2">
            <Button type="submit" disabled={grantAccess.isPending}>
              <Share2 className="h-4 w-4" />
              Accorder
            </Button>
            <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>
              Annuler
            </Button>
          </div>
        </form>
      ) : null}

      {accesses.length === 0 ? (
        <EmptyState title="Aucun accès spécifique" description="Partagez ce document avec un utilisateur." />
      ) : (
        <ul className="divide-y divide-border rounded-lg border border-border">
          {accesses.map((access) => (
            <li key={access.id} className="flex items-center justify-between gap-3 px-4 py-3">
              <div>
                <p className="text-sm font-medium">{access.user?.name}</p>
                <p className="text-xs text-muted-foreground">{access.user?.email}</p>
                <div className="mt-1 flex flex-wrap gap-1">
                  {(access.abilities ?? []).map((a) => (
                    <Badge key={a}>{a}</Badge>
                  ))}
                  <Badge tone={access.is_active ? 'success' : 'danger'}>
                    {access.is_active ? 'Actif' : 'Expiré'}
                  </Badge>
                </div>
                {access.ends_at ? (
                  <p className="mt-1 text-xs text-muted-foreground">
                    Expire : {formatDate(access.ends_at, true)}
                  </p>
                ) : null}
              </div>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  if (window.confirm('Révoquer cet accès ?')) {
                    revokeAccess.mutate(access.id)
                  }
                }}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
