import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Share2, Trash2, UserPlus } from 'lucide-react'
import { toast } from 'sonner'
import { useConfirm } from '@/components/ConfirmDialog'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import Modal from '@/components/ui/Modal'
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { canAny } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { ACCESS_ABILITIES, accessesApi } from '@/modules/access/api'
import { usersApi } from '@/modules/users/api'
import { useAuthStore } from '@/stores/authStore'

const emptyForm = {
  user_ids: [],
  abilities: ['view', 'download'],
  ends_at: '',
  search: '',
}

export default function DocumentAccessPanel({ documentId }) {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(emptyForm)

  const canShare = canAny(user, ['documents.share', 'accesses.manage'])

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.documentAccesses(documentId),
    queryFn: () => accessesApi.listForDocument(documentId),
    enabled: canShare,
  })

  const usersQuery = useQuery({
    queryKey: queryKeys.users({ per_page: 200, is_active: '1' }),
    queryFn: () => usersApi.list({ per_page: 200, is_active: 1 }),
    enabled: showForm && canShare,
  })

  const grantAccess = useMutation({
    mutationFn: () =>
      accessesApi.grantDocument(documentId, {
        user_ids: form.user_ids.map(Number),
        abilities: form.abilities,
        ends_at: form.ends_at ? new Date(form.ends_at).toISOString() : null,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      setShowForm(false)
      setForm(emptyForm)
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
  const alreadyShared = useMemo(
    () => new Set(accesses.map((a) => Number(a.user?.id)).filter(Boolean)),
    [accesses],
  )
  const users = unwrapPaginated(usersQuery.data).data.filter((u) => u.id !== user?.id)
  const filteredUsers = useMemo(() => {
    const q = form.search.trim().toLowerCase()
    return users.filter((u) => {
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
  }, [users, form.search])

  function toggleAbility(ability) {
    setForm((prev) => ({
      ...prev,
      abilities: prev.abilities.includes(ability)
        ? prev.abilities.filter((a) => a !== ability)
        : [...prev.abilities, ability],
    }))
  }

  function toggleUser(id) {
    const sid = String(id)
    setForm((prev) => ({
      ...prev,
      user_ids: prev.user_ids.includes(sid)
        ? prev.user_ids.filter((x) => x !== sid)
        : [...prev.user_ids, sid],
    }))
  }

  function closeForm() {
    setShowForm(false)
    setForm(emptyForm)
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
        <Button size="sm" variant="secondary" onClick={() => setShowForm(true)}>
          <UserPlus className="h-4 w-4" />
          Partager
        </Button>
      </div>

      <Modal
        open={showForm}
        onClose={closeForm}
        title="Partager le document"
        description="Sélectionnez un ou plusieurs utilisateurs et les capacités accordées."
        size="lg"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeForm}>
              Annuler
            </Button>
            <Button
              type="submit"
              form="share-doc-form"
              disabled={grantAccess.isPending || !form.user_ids.length || !form.abilities.length}
            >
              <Share2 className="h-4 w-4" />
              Accorder ({form.user_ids.length})
            </Button>
          </>
        }
      >
        <form
          id="share-doc-form"
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            if (!form.user_ids.length) {
              toast.error('Sélectionnez au moins un utilisateur.')
              return
            }
            grantAccess.mutate()
          }}
        >
          <div>
            <Label htmlFor="share-search">Utilisateurs</Label>
            <Input
              id="share-search"
              className="mt-1"
              placeholder="Rechercher par nom ou e-mail…"
              value={form.search}
              onChange={(e) => setForm({ ...form, search: e.target.value })}
            />
            <div className="mt-2 max-h-56 overflow-y-auto rounded-lg border border-border">
              {filteredUsers.map((u) => {
                const checked = form.user_ids.includes(String(u.id))
                const already = alreadyShared.has(Number(u.id))
                return (
                  <label
                    key={u.id}
                    className="flex cursor-pointer items-start gap-3 border-b border-border px-3 py-2 last:border-b-0 hover:bg-muted/40"
                  >
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={checked}
                      onChange={() => toggleUser(u.id)}
                    />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-medium">{u.name}</span>
                      <span className="block truncate text-xs text-muted-foreground">{u.email}</span>
                    </span>
                    {already ? <Badge>Déjà partagé</Badge> : null}
                  </label>
                )
              })}
              {!filteredUsers.length ? (
                <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                  Aucun utilisateur trouvé.
                </p>
              ) : null}
            </div>
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
        </form>
      </Modal>

      {accesses.length === 0 ? (
        <EmptyState
          title="Aucun accès spécifique"
          description="Partagez ce document avec un ou plusieurs utilisateurs."
        />
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
                onClick={async () => {
                  const ok = await confirm({
                    title: 'Révoquer l’accès',
                    description:
                      'Révoquer cet accès partagé ? L’utilisateur ne pourra plus consulter la ressource.',
                    confirmLabel: 'Révoquer',
                  })
                  if (ok) revokeAccess.mutate(access.id)
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
