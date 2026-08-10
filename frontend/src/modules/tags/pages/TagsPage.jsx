import { useMemo, useState } from 'react'
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
import { getErrorMessage } from '@/lib/api'
import { unwrapList, paginateClient, PAGE_SIZE } from '@/lib/apiHelpers'
import { queryKeys } from '@/lib/queryClient'
import { tagsApi } from '@/modules/tags/api'

export default function TagsPage() {
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [name, setName] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.tags,
    queryFn: tagsApi.list,
  })

  const createTag = useMutation({
    mutationFn: () => tagsApi.create({ name }),
    onSuccess: (res) => {
      toast.success(res.message)
      closeForm()
      queryClient.invalidateQueries({ queryKey: queryKeys.tags })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const updateTag = useMutation({
    mutationFn: () => tagsApi.update(editingId, { name }),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Tag mis à jour.')
      closeForm()
      queryClient.invalidateQueries({ queryKey: queryKeys.tags })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeTag = useMutation({
    mutationFn: (id) => tagsApi.remove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.tags })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const tags = unwrapList(data)
  const { data: pageRows, meta } = useMemo(
    () => paginateClient(tags, page, PAGE_SIZE),
    [tags, page],
  )
  const saving = createTag.isPending || updateTag.isPending

  function closeForm() {
    setShowForm(false)
    setEditingId(null)
    setName('')
  }

  function openCreate() {
    setEditingId(null)
    setName('')
    setShowForm(true)
  }

  function openEdit(tag) {
    setEditingId(tag.id)
    setName(tag.name ?? '')
    setShowForm(true)
  }

  const columns = [
    {
      key: 'name',
      header: 'Tag',
      cell: (t) => <span className="font-medium">{t.name}</span>,
    },
    {
      key: 'slug',
      header: 'Slug',
      cell: (t) => <span className="text-xs text-muted-foreground">{t.slug || '—'}</span>,
    },
    {
      key: 'docs',
      header: 'Documents',
      cell: (t) => t.documents_count ?? 0,
    },
    {
      key: 'actions',
      header: 'Actions',
      className: 'w-[1%] whitespace-nowrap',
      cell: (t) => (
        <div className="flex gap-1">
          <Button variant="ghost" size="sm" title="Modifier" onClick={() => openEdit(t)}>
            <Pencil className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            title="Supprimer"
            onClick={async () => {
              const ok = await confirm({
                title: 'Supprimer le tag',
                description: `Supprimer « ${t.name} » ?`,
                confirmLabel: 'Supprimer',
              })
              if (ok) removeTag.mutate(t.id)
            }}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      ),
    },
  ]

  return (
    <RequirePermission permission="tags.manage">
      <PageHeader
        title="Tags"
        description="Étiquettes pour classer les documents."
        actions={
          <Button size="sm" onClick={openCreate}>
            <Plus className="h-4 w-4" />
            Nouveau tag
          </Button>
        }
      />

      <DataTable
        columns={columns}
        rows={pageRows}
        loading={isLoading}
        emptyTitle="Aucun tag"
        meta={meta}
        onPageChange={setPage}
      />

      <Modal
        open={showForm}
        onClose={closeForm}
        title={editingId ? 'Modifier le tag' : 'Nouveau tag'}
        size="sm"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeForm}>
              Annuler
            </Button>
            <Button type="submit" form="tag-form" disabled={saving}>
              {editingId ? 'Enregistrer' : 'Créer'}
            </Button>
          </>
        }
      >
        <form
          id="tag-form"
          onSubmit={(e) => {
            e.preventDefault()
            if (!name.trim()) return
            if (editingId) updateTag.mutate()
            else createTag.mutate()
          }}
        >
          <Label htmlFor="tag-name">Nom</Label>
          <Input
            id="tag-name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Ex. contrat"
            required
          />
        </form>
      </Modal>
    </RequirePermission>
  )
}
