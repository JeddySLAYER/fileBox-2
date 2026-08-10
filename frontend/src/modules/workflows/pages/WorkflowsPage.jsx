import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Plus } from 'lucide-react'
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
import { workflowsApi } from '@/modules/workflows/api'

const emptyForm = {
  name: '',
  code: '',
  description: '',
  stepName: 'Validation',
}

export default function WorkflowsPage() {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(emptyForm)

  const listParams = { per_page: PAGE_SIZE, page }

  const workflowsQuery = useQuery({
    queryKey: queryKeys.workflows(listParams),
    queryFn: () => workflowsApi.list(listParams),
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
      setForm(emptyForm)
      queryClient.invalidateQueries({ queryKey: ['workflows'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

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
          <Button size="sm" onClick={() => setShowForm(true)}>
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
        onClose={() => {
          setShowForm(false)
          setForm(emptyForm)
        }}
        title="Nouveau workflow"
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setShowForm(false)
                setForm(emptyForm)
              }}
            >
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
          <div className="sm:col-span-2">
            <Label htmlFor="w-step">Première étape</Label>
            <Input
              id="w-step"
              required
              value={form.stepName}
              onChange={(e) => setForm({ ...form, stepName: e.target.value })}
            />
          </div>
        </form>
      </Modal>
    </RequirePermission>
  )
}
