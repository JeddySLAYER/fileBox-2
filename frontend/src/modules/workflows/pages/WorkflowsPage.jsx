import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { GitBranch, Plus } from 'lucide-react'
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
import { unwrapPaginated } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import { queryKeys } from '@/lib/queryClient'
import { workflowsApi } from '@/modules/workflows/api'

export default function WorkflowsPage() {
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({
    name: '',
    code: '',
    description: '',
    stepName: 'Validation',
  })

  const workflowsQuery = useQuery({
    queryKey: queryKeys.workflows({ per_page: 50 }),
    queryFn: () => workflowsApi.list({ per_page: 50 }),
  })

  const createWorkflow = useMutation({
    mutationFn: () =>
      workflowsApi.create({
        name: form.name,
        code: form.code || undefined,
        description: form.description || undefined,
        is_active: true,
        steps: [{ name: form.stepName, step_order: 1, is_mandatory: true }],
      }),
    onSuccess: (data) => {
      toast.success(data.message)
      setShowForm(false)
      setForm({ name: '', code: '', description: '', stepName: 'Validation' })
      queryClient.invalidateQueries({ queryKey: ['workflows'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const { data: workflows, meta } = unwrapPaginated(workflowsQuery.data)

  return (
    <RequirePermission permission="workflows.manage">
      <PageHeader
        title="Workflows"
        description="Circuits de validation (API /workflows)."
        actions={
          <Button size="sm" onClick={() => setShowForm((v) => !v)}>
            <Plus className="h-4 w-4" />
            Nouveau workflow
          </Button>
        }
      />

      {showForm ? (
        <Card className="mb-6">
          <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault()
              createWorkflow.mutate()
            }}
          >
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
            <div>
              <Label htmlFor="w-step">Première étape</Label>
              <Input
                id="w-step"
                required
                value={form.stepName}
                onChange={(e) => setForm({ ...form, stepName: e.target.value })}
              />
            </div>
            <div className="flex items-end gap-2">
              <Button type="submit" disabled={createWorkflow.isPending}>
                Créer
              </Button>
              <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>
                Annuler
              </Button>
            </div>
          </form>
        </Card>
      ) : null}

      {workflowsQuery.isLoading ? (
        <LoadingScreen />
      ) : workflows.length === 0 ? (
        <EmptyState title="Aucun workflow" />
      ) : (
        <div className="grid gap-3">
          {workflows.map((wf) => (
            <Card key={wf.id} className="flex items-start justify-between gap-4">
              <div>
                <div className="flex items-center gap-2">
                  <GitBranch className="h-4 w-4 text-primary" />
                  <Link to={`/workflows/${wf.id}`} className="font-medium hover:text-primary">
                    {wf.name}
                  </Link>
                  <Badge tone={wf.is_active ? 'success' : 'neutral'}>
                    {wf.is_active ? 'Actif' : 'Inactif'}
                  </Badge>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {wf.code} · {wf.steps_count ?? 0} étape(s) · {wf.documents_count ?? 0} document(s)
                </p>
                {wf.description ? (
                  <p className="mt-2 text-sm text-muted-foreground">{wf.description}</p>
                ) : null}
              </div>
            </Card>
          ))}
          {meta ? (
            <p className="text-xs text-muted-foreground">{meta.total} workflow(s)</p>
          ) : null}
        </div>
      )}
    </RequirePermission>
  )
}
