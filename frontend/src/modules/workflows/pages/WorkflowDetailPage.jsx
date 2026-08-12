import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, Plus, Save, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapPaginated } from '@/lib/apiHelpers'
import { can } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { usersApi } from '@/modules/users/api'
import { workflowsApi } from '@/modules/workflows/api'
import { useAuthStore } from '@/stores/authStore'

function emptyStep() {
  return {
    responsible_user_id: '',
    is_mandatory: true,
  }
}

function buildFormFromWorkflow(workflow) {
  return {
    name: workflow.name ?? '',
    code: workflow.code ?? '',
    description: workflow.description ?? '',
    is_active: Boolean(workflow.is_active),
    steps:
      (workflow.steps ?? []).length > 0
        ? workflow.steps.map((s) => ({
            responsible_user_id: s.responsible_user?.id ?? '',
            is_mandatory: s.is_mandatory ?? true,
          }))
        : [emptyStep()],
  }
}

export default function WorkflowDetailPage() {
  const { id } = useParams()
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const canEdit = can(user, 'workflows.manage')
  const [formDraft, setFormDraft] = useState(null)

  const { data: workflow, isLoading, isError } = useQuery({
    queryKey: queryKeys.workflow(id),
    queryFn: () => workflowsApi.get(id),
    enabled: Boolean(id),
  })

  const usersQuery = useQuery({
    queryKey: queryKeys.users({ per_page: 200, is_active: '1' }),
    queryFn: () => usersApi.list({ per_page: 200, is_active: 1 }),
    enabled: canEdit,
  })

  const save = useMutation({
    mutationFn: () => {
      const form = formDraft ?? buildFormFromWorkflow(workflow)
      if (form.steps.some((s) => !s.responsible_user_id)) {
        throw new Error('Chaque validation doit avoir un utilisateur.')
      }
      const ids = form.steps.map((s) => String(s.responsible_user_id))
      if (new Set(ids).size !== ids.length) {
        throw new Error('Un utilisateur ne peut être choisi qu’une fois dans le workflow.')
      }
      return workflowsApi.update(id, {
        name: form.name,
        code: form.code || undefined,
        description: form.description || null,
        is_active: form.is_active,
        steps: form.steps.map((s, i) => ({
          name: `Validation ${i + 1}`,
          step_order: i + 1,
          responsible_user_id: Number(s.responsible_user_id),
          responsible_role_id: null,
          is_mandatory: Boolean(s.is_mandatory),
        })),
      })
    },
    onSuccess: (res) => {
      toast.success(res.message)
      setFormDraft(null)
      queryClient.invalidateQueries({ queryKey: queryKeys.workflow(id) })
      queryClient.invalidateQueries({ queryKey: ['workflows'] })
    },
    onError: (e) => toast.error(getErrorMessage(e, e.message)),
  })

  const users = unwrapPaginated(usersQuery.data).data

  if (isLoading) return <LoadingScreen />
  if (isError || !workflow) return <EmptyState title="Workflow introuvable" />

  const form = formDraft ?? buildFormFromWorkflow(workflow)
  const setForm = (next) => setFormDraft(typeof next === 'function' ? next(form) : next)

  const takenUserIds = useMemo(
    () => new Set(form.steps.map((s) => String(s.responsible_user_id)).filter(Boolean)),
    [form.steps],
  )

  function updateStep(index, patch) {
    const steps = [...form.steps]
    steps[index] = { ...steps[index], ...patch }
    setForm({ ...form, steps })
  }

  return (
    <>
      <PageHeader
        title={workflow.name}
        description={`${workflow.code} · ${workflow.steps_count ?? 0} validation(s)`}
        actions={
          <>
            <Button as={Link} to="/workflows" variant="secondary" size="sm">
              <ArrowLeft className="h-4 w-4" />
              Retour
            </Button>
            {canEdit ? (
              <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>
                <Save className="h-4 w-4" />
                Enregistrer
              </Button>
            ) : null}
          </>
        }
      />

      <div className="grid gap-4 lg:grid-cols-[1fr_1.2fr]">
        <Card>
          <h2 className="text-sm font-semibold">Paramètres</h2>
          <div className="mt-4 space-y-3">
            <div>
              <Label>Nom</Label>
              <Input
                disabled={!canEdit}
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
            </div>
            <div>
              <Label>Code</Label>
              <Input
                disabled={!canEdit}
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
              />
            </div>
            <div>
              <Label>Description</Label>
              <Input
                disabled={!canEdit}
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
              />
            </div>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                disabled={!canEdit}
                checked={form.is_active}
                onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
              />
              Actif
            </label>
          </div>
        </Card>

        <Card>
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">Validations</h2>
            {canEdit ? (
              <Button
                size="sm"
                variant="secondary"
                onClick={() => setForm({ ...form, steps: [...form.steps, emptyStep()] })}
              >
                <Plus className="h-4 w-4" />
                Ajouter
              </Button>
            ) : null}
          </div>
          <p className="mt-1 text-xs text-muted-foreground">
            Un utilisateur ne peut apparaître qu’une seule fois dans le circuit.
          </p>

          <ul className="mt-4 space-y-3">
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
                    {canEdit && form.steps.length > 1 ? (
                      <Button
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
                      disabled={!canEdit}
                      className="mt-1 h-11 w-full rounded-lg border border-border bg-background px-3 text-sm disabled:opacity-50"
                      value={step.responsible_user_id}
                      onChange={(e) => updateStep(index, { responsible_user_id: e.target.value })}
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
        </Card>
      </div>
    </>
  )
}
