import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileType, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import RequirePermission from '@/components/RequirePermission'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
import { queryKeys } from '@/lib/queryClient'
import { documentTypesApi } from '@/modules/document-types/api'
import { workflowsApi } from '@/modules/workflows/api'

export default function DocumentTypesPage() {
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ name: '', description: '', default_workflow_id: '' })

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
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      setShowForm(false)
      setForm({ name: '', description: '', default_workflow_id: '' })
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
  const workflows = unwrapPaginated(workflowsQuery.data).data

  return (
    <RequirePermission permission="document_types.manage">
      <PageHeader
        title="Types de documents"
        description="Classification métier (API /document-types)."
        actions={
          <Button size="sm" onClick={() => setShowForm((v) => !v)}>
            <Plus className="h-4 w-4" />
            Nouveau type
          </Button>
        }
      />

      {showForm ? (
        <Card className="mb-6">
          <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault()
              createType.mutate()
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
                className="h-11 w-full rounded-lg border border-border bg-background px-3 text-sm"
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
            <div className="flex gap-2 sm:col-span-2">
              <Button type="submit" disabled={createType.isPending}>
                Créer
              </Button>
              <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>
                Annuler
              </Button>
            </div>
          </form>
        </Card>
      ) : null}

      {isLoading ? (
        <LoadingScreen />
      ) : types.length === 0 ? (
        <EmptyState title="Aucun type" />
      ) : (
        <div className="grid gap-3">
          {types.map((type) => (
            <Card key={type.id} className="flex items-start justify-between gap-3">
              <div>
                <div className="flex items-center gap-2">
                  <FileType className="h-4 w-4 text-primary" />
                  <p className="font-medium">{type.name}</p>
                  <span className="text-xs text-muted-foreground">{type.slug}</span>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {type.documents_count ?? 0} document(s)
                  {type.default_workflow
                    ? ` · workflow : ${type.default_workflow.name}`
                    : ''}
                </p>
                {type.description ? (
                  <p className="mt-2 text-sm text-muted-foreground">{type.description}</p>
                ) : null}
              </div>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  if (window.confirm(`Supprimer ${type.name} ?`)) removeType.mutate(type.id)
                }}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </Card>
          ))}
        </div>
      )}
    </RequirePermission>
  )
}
