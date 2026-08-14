import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Pencil, Plus, Trash2 } from 'lucide-react'
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
import { unwrapPaginated, PAGE_SIZE } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import {
  emptyStepTiming,
  stepTimingFromApi,
  timingPayload,
  validateStepTiming,
} from '@/lib/duration'
import { queryKeys } from '@/lib/queryClient'
import { usersApi } from '@/modules/users/api'
import { workflowsApi } from '@/modules/workflows/api'
import WorkflowStepTimingFields from '@/modules/workflows/components/WorkflowStepTimingFields'

function emptyStep() {
  return { responsible_user_id: '', is_mandatory: true, ...emptyStepTiming() }
}

const emptyForm = {
  name: '',
  code: '',
  description: '',
  is_active: true,
  steps: [emptyStep()],
}

export default function WorkflowsPage() {
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(emptyForm)

  const listParams = { per_page: PAGE_SIZE, page }

  const workflowsQuery = useQuery({
    queryKey: queryKeys.workflows(listParams),
    queryFn: () => workflowsApi.list(listParams),
  })

  const usersQuery = useQuery({
    queryKey: queryKeys.users({ per_page: 200, is_active: '1' }),
    queryFn: () => usersApi.list({ per_page: 200, is_active: 1 }),
    enabled: showForm,
  })

  const users = unwrapPaginated(usersQuery.data).data

  const takenUserIds = useMemo(
    () => new Set(form.steps.map((s) => String(s.responsible_user_id)).filter(Boolean)),
    [form.steps],
  )

  const createWorkflow = useMutation({
    mutationFn: () => workflowsApi.create(workflowPayload()),
    onSuccess: (data) => {
      toast.success(data.message)
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['workflows'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const updateWorkflow = useMutation({
    mutationFn: () => workflowsApi.update(editingId, workflowPayload()),
    onSuccess: (data) => {
      toast.success(data.message)
      closeForm()
      queryClient.invalidateQueries({ queryKey: ['workflows'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeWorkflow = useMutation({
    mutationFn: (id) => workflowsApi.remove(id),
    onSuccess: (data) => {
      toast.success(data.message)
      queryClient.invalidateQueries({ queryKey: ['workflows'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  function workflowPayload() {
    return {
      name: form.name,
      code: form.code || undefined,
      description: form.description || undefined,
      is_active: Boolean(form.is_active),
      steps: form.steps.map((s, i) => ({
        name: `Validation ${i + 1}`,
        step_order: i + 1,
        responsible_user_id: Number(s.responsible_user_id),
        is_mandatory: Boolean(s.is_mandatory),
        ...timingPayload(s),
      })),
    }
  }

  function updateStep(index, patch) {
    setForm((prev) => {
      const steps = [...prev.steps]
      steps[index] = { ...steps[index], ...patch }
      return { ...prev, steps }
    })
  }

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

  function openEdit(wf) {
    if (wf.in_use) return
    setEditingId(wf.id)
    setForm({
      name: wf.name ?? '',
      code: wf.code ?? '',
      description: wf.description ?? '',
      is_active: Boolean(wf.is_active),
      steps:
        (wf.steps ?? []).length > 0
          ? wf.steps.map((s) => ({
              responsible_user_id: s.responsible_user?.id ?? '',
              is_mandatory: s.is_mandatory ?? true,
              ...stepTimingFromApi(s),
            }))
          : [emptyStep()],
    })
    setShowForm(true)
  }

  const { data: workflows, meta } = unwrapPaginated(workflowsQuery.data)

  const columns = [
    {
      key: 'name',
      header: 'Workflow',
      cell: (wf) => (
        <div>
          <Link to={`/workflows/${wf.id}`} className="font-medium hover:text-primary">
            {wf.name}
          </Link>
          {wf.description ? (
            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{wf.description}</p>
          ) : null}
        </div>
      ),
    },
    {
      key: 'code',
      header: 'Code',
      cell: (wf) => <span className="text-muted-foreground">{wf.code || '—'}</span>,
    },
    {
      key: 'status',
      header: 'Statut',
      cell: (wf) => (
        <Badge tone={wf.is_active ? 'success' : 'neutral'}>
          {wf.is_active ? 'Actif' : 'Inactif'}
        </Badge>
      ),
    },
    {
      key: 'steps',
      header: 'Étapes',
      cell: (wf) => wf.steps_count ?? 0,
    },
    {
      key: 'docs',
      header: 'Documents',
      cell: (wf) => wf.documents_count ?? 0,
    },
    {
      key: 'actions',
      header: 'Actions',
      className: 'w-[1%] whitespace-nowrap',
      cell: (wf) => (
        <div className="flex gap-1">
          <Button
            variant="ghost"
            size="sm"
            title={
              wf.in_use
                ? 'Impossible : des documents sont en cours de validation'
                : 'Modifier'
            }
            disabled={wf.in_use}
            onClick={() => openEdit(wf)}
          >
            <Pencil className="h-4 w-4" />
          </Button>
          <Button
          variant="ghost"
          size="sm"
          title={
            wf.in_use
              ? 'Impossible : des documents sont en cours de validation'
              : 'Supprimer'
          }
          disabled={wf.in_use || removeWorkflow.isPending}
          onClick={async () => {
            const ok = await confirm({
              title: 'Supprimer le workflow',
              description: `Supprimer « ${wf.name} » ? Les documents et types qui l’utilisent n’y seront plus liés.`,
              confirmLabel: 'Supprimer',
            })
            if (ok) removeWorkflow.mutate(wf.id)
          }}
        >
          <Trash2 className="h-4 w-4" />
        </Button>
        </div>
      ),
    },
  ]

  return (
    <RequirePermission permission="workflows.manage">
      <PageHeader
        title="Workflows"
        description="Circuits de validation documentaire. Modifiez ou supprimez un workflow tant qu’aucun document n’est en cours de validation."
        actions={
          <Button
            size="sm"
            onClick={openCreate}
          >
            <Plus className="h-4 w-4" />
            Nouveau workflow
          </Button>
        }
      />

      <DataTable
        columns={columns}
        rows={workflows}
        loading={workflowsQuery.isLoading}
        emptyTitle="Aucun workflow"
        meta={meta}
        onPageChange={setPage}
      />

      <Modal
        open={showForm}
        onClose={closeForm}
        title={editingId ? 'Modifier le workflow' : 'Nouveau workflow'}
        description="Chaque étape est une validation assignée à un utilisateur, avec une durée et un rappel."
        size="lg"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeForm}>
              Annuler
            </Button>
            <Button
              type="submit"
              form="wf-form"
              disabled={createWorkflow.isPending || updateWorkflow.isPending}
            >
              {editingId ? 'Enregistrer' : 'Créer'}
            </Button>
          </>
        }
      >
        <form
          id="wf-form"
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            if (!form.steps.length) {
              toast.error('Ajoutez au moins une étape.')
              return
            }
            if (form.steps.some((s) => !s.responsible_user_id)) {
              toast.error('Chaque validation doit avoir un utilisateur.')
              return
            }
            const ids = form.steps.map((s) => String(s.responsible_user_id))
            if (new Set(ids).size !== ids.length) {
              toast.error('Un utilisateur ne peut être choisi qu’une fois dans le workflow.')
              return
            }
            const timingError = form.steps.map(validateStepTiming).find(Boolean)
            if (timingError) {
              toast.error(timingError)
              return
            }
            if (editingId) updateWorkflow.mutate()
            else createWorkflow.mutate()
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="w-name">Nom</Label>
              <Input
                id="w-name"
                required
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
            </div>
            <div>
              <Label htmlFor="w-code">Code (optionnel)</Label>
              <Input
                id="w-code"
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="w-desc">Description</Label>
              <Input
                id="w-desc"
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
              />
            </div>
            {editingId ? (
              <label className="sm:col-span-2 flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={Boolean(form.is_active)}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                />
                Workflow actif
              </label>
            ) : null}
          </div>

          <div>
            <div className="mb-2 flex items-center justify-between gap-2">
              <Label>Validations</Label>
              <Button
                type="button"
                size="sm"
                variant="secondary"
                onClick={() => setForm({ ...form, steps: [...form.steps, emptyStep()] })}
              >
                <Plus className="h-4 w-4" />
                Ajouter
              </Button>
            </div>

            <ul className="space-y-3">
              {form.steps.map((step, index) => {
                const options = users.filter(
                  (u) =>
                    String(u.id) === String(step.responsible_user_id) ||
                    !takenUserIds.has(String(u.id)),
                )
                return (
                  <li key={index} className="rounded-lg border border-border p-3">
                    <div className="mb-2 flex items-center justify-between">
                      <Badge>Validation {index + 1}</Badge>
                      {form.steps.length > 1 ? (
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          onClick={() =>
                            setForm({
                              ...form,
                              steps: form.steps.filter((_, i) => i !== index),
                            })
                          }
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                      ) : null}
                    </div>
                    <div>
                      <Label>Utilisateur</Label>
                      <select
                        required
                        className="mt-1 h-11 w-full rounded-lg border border-border bg-background px-3 text-sm"
                        value={step.responsible_user_id}
                        onChange={(e) =>
                          updateStep(index, { responsible_user_id: e.target.value })
                        }
                      >
                        <option value="">— Choisir —</option>
                        {options.map((u) => (
                          <option key={u.id} value={u.id}>
                            {u.name} ({u.email})
                          </option>
                        ))}
                      </select>
                    </div>
                    <WorkflowStepTimingFields
                      step={step}
                      onChange={(patch) => updateStep(index, patch)}
                    />
                  </li>
                )
              })}
            </ul>
          </div>
        </form>
      </Modal>
    </RequirePermission>
  )
}
