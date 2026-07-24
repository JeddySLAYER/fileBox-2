import { useEffect, useState } from 'react'
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
import { unwrapList } from '@/lib/apiHelpers'
import { can } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { rolesApi } from '@/modules/roles/api'
import { workflowsApi } from '@/modules/workflows/api'
import { useAuthStore } from '@/stores/authStore'

function emptyStep(order = 1) {
  return {
    name: `Étape ${order}`,
    step_order: order,
    responsible_role_id: '',
    is_mandatory: true,
    description: '',
  }
}

export default function WorkflowDetailPage() {
  const { id } = useParams()
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const canEdit = can(user, 'workflows.manage')

  const [form, setForm] = useState({
    name: '',
    code: '',
    description: '',
    is_active: true,
    steps: [emptyStep()],
  })

  const { data: workflow, isLoading, isError } = useQuery({
    queryKey: queryKeys.workflow(id),
    queryFn: () => workflowsApi.get(id),
    enabled: Boolean(id),
  })

  const rolesQuery = useQuery({
    queryKey: queryKeys.roles,
    queryFn: rolesApi.list,
    enabled: canEdit,
  })

  useEffect(() => {
    if (!workflow) return
    setForm({
      name: workflow.name ?? '',
      code: workflow.code ?? '',
      description: workflow.description ?? '',
      is_active: Boolean(workflow.is_active),
      steps:
        (workflow.steps ?? []).length > 0
          ? workflow.steps.map((s, i) => ({
              name: s.name,
              step_order: s.step_order ?? i + 1,
              responsible_role_id: s.responsible_role?.id ?? '',
              is_mandatory: s.is_mandatory ?? true,
              description: s.description ?? '',
            }))
          : [emptyStep()],
    })
  }, [workflow])

  const save = useMutation({
    mutationFn: () =>
      workflowsApi.update(id, {
        name: form.name,
        code: form.code || undefined,
        description: form.description || null,
        is_active: form.is_active,
        steps: form.steps.map((s, i) => ({
          name: s.name,
          step_order: i + 1,
          responsible_role_id: s.responsible_role_id ? Number(s.responsible_role_id) : null,
          is_mandatory: Boolean(s.is_mandatory),
          description: s.description || null,
        })),
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.workflow(id) })
      queryClient.invalidateQueries({ queryKey: ['workflows'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const roles = unwrapList(rolesQuery.data)

  if (isLoading) return <LoadingScreen />
  if (isError || !workflow) return <EmptyState title="Workflow introuvable" />

  return (
    <>
      <PageHeader
        title={workflow.name}
        description={`${workflow.code} · ${workflow.steps_count ?? 0} étape(s)`}
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
            <h2 className="text-sm font-semibold">Étapes</h2>
            {canEdit ? (
              <Button
                size="sm"
                variant="secondary"
                onClick={() =>
                  setForm({
                    ...form,
                    steps: [...form.steps, emptyStep(form.steps.length + 1)],
                  })
                }
              >
                <Plus className="h-4 w-4" />
                Étape
              </Button>
            ) : null}
          </div>

          <ul className="mt-4 space-y-3">
            {form.steps.map((step, index) => (
              <li key={index} className="rounded-lg border border-border p-3">
                <div className="mb-2 flex items-center justify-between">
                  <Badge>#{index + 1}</Badge>
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
                <div className="grid gap-2 sm:grid-cols-2">
                  <div>
                    <Label>Nom</Label>
                    <Input
                      disabled={!canEdit}
                      value={step.name}
                      onChange={(e) => {
                        const steps = [...form.steps]
                        steps[index] = { ...step, name: e.target.value }
                        setForm({ ...form, steps })
                      }}
                    />
                  </div>
                  <div>
                    <Label>Rôle responsable</Label>
                    <select
                      disabled={!canEdit}
                      className="h-11 w-full rounded-lg border border-border bg-background px-3 text-sm disabled:opacity-50"
                      value={step.responsible_role_id}
                      onChange={(e) => {
                        const steps = [...form.steps]
                        steps[index] = { ...step, responsible_role_id: e.target.value }
                        setForm({ ...form, steps })
                      }}
                    >
                      <option value="">— Aucun —</option>
                      {roles.map((r) => (
                        <option key={r.id} value={r.id}>
                          {r.name}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
                <label className="mt-2 flex items-center gap-2 text-xs">
                  <input
                    type="checkbox"
                    disabled={!canEdit}
                    checked={step.is_mandatory}
                    onChange={(e) => {
                      const steps = [...form.steps]
                      steps[index] = { ...step, is_mandatory: e.target.checked }
                      setForm({ ...form, steps })
                    }}
                  />
                  Étape obligatoire
                </label>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </>
  )
}
