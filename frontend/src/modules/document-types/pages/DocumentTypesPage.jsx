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
import { unwrapList, unwrapPaginated, paginateClient, PAGE_SIZE } from '@/lib/apiHelpers'
import { queryKeys } from '@/lib/queryClient'
import { documentTypesApi } from '@/modules/document-types/api'
import { workflowsApi } from '@/modules/workflows/api'

const emptyForm = { name: '', description: '', default_workflow_id: '', requires_workflow: false }
const selectClass = 'h-11 w-full rounded-lg border border-border bg-background px-3 text-sm'

export default function DocumentTypesPage() {
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(emptyForm)

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.documentTypes({}),
    queryFn: () => documentTypesApi.list(),
  })

  const workflowsQuery = useQuery({
    queryKey: queryKeys.workflows({ per_page: 50 }),
    queryFn: () => workflowsApi.list({ per_page: 50 }),
    enabled: showForm,
  })

  const createType = useMutation({
    mutationFn: () =>
      documentTypesApi.create({
        name: form.name,
        description: form.description || undefined,
        default_workflow_id: form.default_workflow_id
          ? Number(form.default_workflow_id)
          : null,
        requires_workflow: Boolean(form.requires_workflow),
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['document-types'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const updateType = useMutation({
    mutationFn: () =>
      documentTypesApi.update(editingId, {
        name: form.name,
        description: form.description || null,
        default_workflow_id: form.default_workflow_id
          ? Number(form.default_workflow_id)
          : null,
        requires_workflow: Boolean(form.requires_workflow),
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['document-types'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeType = useMutation({
    mutationFn: (id) => documentTypesApi.remove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['document-types'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const types = unwrapList(data)
  const { data: pageRows, meta } = useMemo(
    () => paginateClient(types, page, PAGE_SIZE),
    [types, page],
  )
  const workflows = unwrapPaginated(workflowsQuery.data).data
  const saving = createType.isPending || updateType.isPending

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

  function openEdit(t) {
    setEditingId(t.id)
    setForm({
      name: t.name ?? '',
      description: t.description ?? '',
      default_workflow_id: t.default_workflow?.id ? String(t.default_workflow.id) : '',
      requires_workflow: Boolean(t.requires_workflow),
    })
    setShowForm(true)
  }

  const columns = [
    {
      key: 'name',
      header: 'Type',
      cell: (t) => (
        <div>
          <p className="font-medium">{t.name}</p>
          {t.description ? (
            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{t.description}</p>
          ) : null}
        </div>
      ),
    },
    {
      key: 'slug',
      header: 'Slug',
      cell: (t) => <span className="text-xs text-muted-foreground">{t.slug}</span>,
    },
    {
      key: 'workflow',
      header: 'Workflow',
      cell: (t) => t.default_workflow?.name ?? <span className="text-muted-foreground">—</span>,
    },
    {
      key: 'required',
      header: 'Validations',
      cell: (t) =>
        t.requires_workflow ? (
          <span className="text-xs font-medium">Obligatoires</span>
        ) : (
          <span className="text-xs text-muted-foreground">Sur proposition</span>
        ),
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
                title: 'Supprimer le type',
                description: `Supprimer « ${t.name} » ? Les documents concernés n’auront plus ce type.`,
                confirmLabel: 'Supprimer',
              })
              if (ok) removeType.mutate(t.id)
            }}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      ),
    },
  ]

  return (
    <RequirePermission permission="document_types.manage">
      <PageHeader
        title="Types de documents"
        description="Classification métier des documents. Modifier ou supprimer un type retire simplement l’association sur les documents concernés."
        actions={
          <Button size="sm" onClick={openCreate}>
            <Plus className="h-4 w-4" />
            Nouveau type
          </Button>
        }
      />

      <DataTable
        columns={columns}
        rows={pageRows}
        loading={isLoading}
        emptyTitle="Aucun type"
        meta={meta}
        onPageChange={setPage}
      />

      <Modal
        open={showForm}
        onClose={closeForm}
        title={editingId ? 'Modifier le type de document' : 'Nouveau type de document'}
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeForm}>
              Annuler
            </Button>
            <Button type="submit" form="dtype-form" disabled={saving}>
              {editingId ? 'Enregistrer' : 'Créer'}
            </Button>
          </>
        }
      >
        <form
          id="dtype-form"
          className="grid gap-3 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (form.requires_workflow && !form.default_workflow_id) {
              toast.error('Choisissez un workflow par défaut : ce type exige un circuit de validation.')
              return
            }
            if (editingId) updateType.mutate()
            else createType.mutate()
          }}
        >
          <div>
            <Label htmlFor="t-name">Nom</Label>
            <Input
              id="t-name"
              required
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
          </div>
          <div>
            <Label htmlFor="t-wf">Workflow par défaut</Label>
            <select
              id="t-wf"
              className={selectClass}
              value={form.default_workflow_id}
              onChange={(e) => setForm({ ...form, default_workflow_id: e.target.value })}
            >
              <option value="">— Aucun —</option>
              {workflows.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="t-desc">Description</Label>
            <Input
              id="t-desc"
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
            />
          </div>
          <label className="sm:col-span-2 flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              className="mt-1"
              checked={Boolean(form.requires_workflow)}
              onChange={(e) =>
                setForm({ ...form, requires_workflow: e.target.checked })
              }
            />
            <span>
              Exiger un circuit de validation
              <span className="mt-0.5 block text-xs text-muted-foreground">
                Les documents de ce type devront suivre le workflow, même sans proposition
                préalable. Un workflow par défaut est alors obligatoire.
              </span>
            </span>
          </label>
        </form>
      </Modal>
    </RequirePermission>
  )
}
