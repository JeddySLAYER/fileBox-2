import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
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
import { queryKeys } from '@/lib/queryClient'
import { usersApi } from '@/modules/users/api'
import { workflowsApi } from '@/modules/workflows/api'

function emptyStep() {
  return { responsible_user_id: '', is_mandatory: true }
}

const emptyForm = {
  name: '',
  code: '',
  description: '',
  steps: [emptyStep()],
}

export default function WorkflowsPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
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
    mutationFn: () =>
      workflowsApi.create({
        name: form.name,
        code: form.code || undefined,
        description: form.description || undefined,
        is_active: true,
        steps: form.steps.map((s, i) => ({
          name: `Validation ${i + 1}`,
          step_order: i + 1,
          responsible_user_id: Number(s.responsible_user_id),
          is_mandatory: Boolean(s.is_mandatory),
        })),
      }),
    onSuccess: (data) => {
      toast.success(data.message)
      setShowForm(false)
      setForm(emptyForm)
      queryClient.invalidateQueries({ queryKey: ['workflows'] })
      const id = data.workflow?.id
      if (id) navigate(`/workflows/${id}`)
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  function updateStep(index, patch) {
    setForm((prev) => {
      const steps = [...prev.steps]
      steps[index] = { ...steps[index], ...patch }
      return { ...prev, steps }
    })
  }

  function closeForm() {
    setShowForm(false)
    setForm(emptyForm)
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
  ]

  return (
    <RequirePermission permission="workflows.manage">
      <PageHeader
        title="Workflows"
        description="Circuits de validation documentaire."
        actions={
          <Button
            size="sm"
            onClick={() => {
              setForm(emptyForm)
              setShowForm(true)
            }}
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
        title="Nouveau workflow"
        description="Chaque étape est une validation assignée à un utilisateur distinct."
        size="lg"
        footer={
          <>
            <Button type="button" variant="secondary" onClick={closeForm}>
              Annuler
            </Button>
            <Button type="submit" form="wf-form" disabled={createWorkflow.isPending}>
              Créer
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
            createWorkflow.mutate()
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
