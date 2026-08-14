import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { Check, Eye, Play, X } from 'lucide-react'
import { toast } from 'sonner'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import Tabs from '@/components/ui/Tabs'
import { getErrorMessage } from '@/lib/api'
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
import { partsFromHours } from '@/lib/duration'
import { formatDate, statusLabel } from '@/lib/format'
import { canAny } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { documentsApi } from '@/modules/documents/api'
import { validationsApi } from '@/modules/validations/api'
import { workflowsApi } from '@/modules/workflows/api'
import { useAuthStore } from '@/stores/authStore'

export default function ProposedDocumentsPage() {
  const user = useAuthStore((s) => s.user)
  const canManage = canAny(user, ['workflows.manage', 'projects.manage'])
  const [params, setParams] = useSearchParams()
  const requested = params.get('tab')
  const tab = !canManage ? 'suivre' : requested === 'suivre' ? 'suivre' : 'propositions'

  function setTab(next) {
    const p = new URLSearchParams(params)
    p.set('tab', next)
    setParams(p, { replace: true })
  }

  const tabs = [
    canManage ? { id: 'propositions', label: 'Propositions' } : null,
    { id: 'suivre', label: 'À suivre' },
  ].filter(Boolean)

  return (
    <>
      <PageHeader
        title="Validations"
        description={
          canManage
            ? 'Démarrez les propositions, puis traitez les étapes qui vous sont assignées.'
            : 'Traitez les étapes de validation qui vous sont assignées.'
        }
      />
      {tabs.length > 1 ? (
        <div className="mb-4">
          <Tabs tabs={tabs} active={tab} onChange={setTab} />
        </div>
      ) : null}
      {tab === 'propositions' && canManage ? <PropositionsPanel /> : <InboxPanel />}
    </>
  )
}

function PropositionsPanel() {
  const queryClient = useQueryClient()
  const [startId, setStartId] = useState(null)

  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.documents({ status: 'propose' }),
    queryFn: () => documentsApi.list({ status: 'propose', per_page: 50 }),
  })

  const startWorkflow = useMutation({
    mutationFn: ({ doc, workflowId, deadlines }) =>
      validationsApi.start(doc.id, workflowId, deadlines),
    onSuccess: (res) => {
      toast.success(res.message)
      setStartId(null)
      queryClient.invalidateQueries({ queryKey: ['documents'] })
      queryClient.invalidateQueries({ queryKey: queryKeys.validationsInbox })
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const documents = unwrapPaginated(data).data

  if (isLoading) return <LoadingScreen label="Propositions…" />
  if (isError) return <EmptyState title="Impossible de charger les propositions" />
  if (documents.length === 0) {
    return (
      <EmptyState
        title="Aucune proposition en attente"
        description="Les documents proposés par les collaborateurs apparaîtront ici."
      />
    )
  }

  return (
    <ul className="divide-y divide-border rounded-xl border border-border bg-background">
      {documents.map((doc) => (
        <li key={doc.id} className="px-4 py-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <Link to={`/documents/${doc.id}`} className="font-medium hover:text-primary">
                  {doc.title}
                </Link>
                <Badge tone="warning">{statusLabel(doc.status)}</Badge>
              </div>
              <p className="mt-0.5 text-xs text-muted-foreground">
                {doc.reference}
                {doc.project?.name ? ` · ${doc.project.name}` : ''}
                {doc.document_type?.name ? ` · ${doc.document_type.name}` : ''}
                {doc.author?.name ? ` · ${doc.author.name}` : ''}
                {doc.workflow?.name ? ` · ${doc.workflow.name}` : ' · aucun workflow'}
                {doc.updated_at ? ` · ${formatDate(doc.updated_at, true)}` : ''}
              </p>
            </div>
            <div className="flex shrink-0 gap-2">
              <Button as={Link} to={`/documents/${doc.id}?tab=validations`} size="sm" variant="secondary">
                <Eye className="h-4 w-4" />
                Voir
              </Button>
              <Button
                size="sm"
                variant={startId === doc.id ? 'secondary' : 'primary'}
                onClick={() => setStartId((id) => (id === doc.id ? null : doc.id))}
                disabled={!doc.can_start_workflow && startId !== doc.id}
              >
                <Play className="h-4 w-4" />
                {startId === doc.id ? 'Annuler' : 'Démarrer'}
              </Button>
            </div>
          </div>
          {startId === doc.id ? (
            <StartWorkflowForm
              document={doc}
              pending={startWorkflow.isPending}
              onStart={(payload) => startWorkflow.mutate({ doc, ...payload })}
            />
          ) : null}
        </li>
      ))}
    </ul>
  )
}

function StartWorkflowForm({ document, pending, onStart }) {
  const [workflowId, setWorkflowId] = useState(document.workflow?.id ? String(document.workflow.id) : '')
  const [deadlineOverrides, setDeadlineOverrides] = useState({})

  const workflowsQuery = useQuery({
    queryKey: queryKeys.workflows({ is_active: 1 }),
    queryFn: () => workflowsApi.list({ is_active: 1, per_page: 50 }),
  })
  const workflows = unwrapPaginated(workflowsQuery.data).data
  const effectiveId = workflowId || (document.workflow?.id ? String(document.workflow.id) : '')

  const selectedQuery = useQuery({
    queryKey: queryKeys.workflow(effectiveId),
    queryFn: () => workflowsApi.get(effectiveId),
    enabled: Boolean(effectiveId),
  })
  const steps = selectedQuery.data?.steps ?? []
  const defaults = useMemo(
    () => Object.fromEntries(steps.map((s) => [s.id, partsFromHours(s.duration_hours)])),
    [steps],
  )
  const deadlines = { ...defaults, ...deadlineOverrides }

  return (
    <div className="mt-3 space-y-3 rounded-lg border border-dashed border-border p-3">
      <select
        className="h-11 w-full rounded-lg border border-border bg-background px-3 text-sm"
        value={workflowId}
        onChange={(e) => {
          setWorkflowId(e.target.value)
          setDeadlineOverrides({})
        }}
      >
        <option value="">
          {document.workflow?.name
            ? `— ${document.workflow.name} (par défaut) —`
            : '— Choisir un workflow —'}
        </option>
        {workflows.map((w) => (
          <option key={w.id} value={w.id}>
            {w.name}
          </option>
        ))}
      </select>
      {effectiveId && steps.length > 0 ? (
        <ul className="space-y-2">
          {steps.map((step) => (
            <li key={step.id} className="flex flex-wrap items-center gap-2 text-sm">
              <span className="min-w-[140px] flex-1">
                {step.step_order}. {step.name}
              </span>
              <Input
                type="number"
                min={1}
                className="h-9 w-20"
                value={deadlines[step.id]?.amount ?? ''}
                onChange={(e) =>
                  setDeadlineOverrides((prev) => ({
                    ...prev,
                    [step.id]: {
                      amount: e.target.value,
                      unit: prev[step.id]?.unit ?? defaults[step.id]?.unit ?? 'days',
                    },
                  }))
                }
              />
              <select
                className="h-9 rounded-lg border border-border bg-background px-2 text-sm"
                value={deadlines[step.id]?.unit ?? 'days'}
                onChange={(e) =>
                  setDeadlineOverrides((prev) => ({
                    ...prev,
                    [step.id]: {
                      amount: prev[step.id]?.amount ?? defaults[step.id]?.amount ?? '1',
                      unit: e.target.value,
                    },
                  }))
                }
              >
                <option value="hours">heures</option>
                <option value="days">jours</option>
              </select>
            </li>
          ))}
        </ul>
      ) : null}
      <Button
        size="sm"
        disabled={(!workflowId && !document.workflow?.id) || pending}
        onClick={() => {
          const payload = Object.entries(deadlines)
            .filter(([, d]) => d?.amount && Number(d.amount) >= 1)
            .map(([stepId, d]) => ({
              workflow_step_id: Number(stepId),
              amount: Number(d.amount),
              unit: d.unit,
            }))
          onStart({
            workflowId: workflowId ? Number(workflowId) : document.workflow?.id ?? null,
            deadlines: payload,
          })
        }}
      >
        <Play className="h-4 w-4" />
        Lancer le circuit
      </Button>
    </div>
  )
}

function InboxPanel() {
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.validationsInbox,
    queryFn: validationsApi.inbox,
  })

  const items = unwrapList(data)

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: queryKeys.validationsInbox })
    queryClient.invalidateQueries({ queryKey: ['documents'] })
    queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
  }

  if (isLoading) return <LoadingScreen label="Validations…" />
  if (isError) return <EmptyState title="Impossible de charger les validations à suivre" />
  if (items.length === 0) {
    return (
      <EmptyState
        title="Rien à suivre pour le moment"
        description="Les étapes de validation qui vous sont assignées apparaîtront ici."
      />
    )
  }

  return (
    <ul className="space-y-3">
      {items.map((item) => (
        <PendingCard key={item.id} item={item} onDone={invalidate} />
      ))}
    </ul>
  )
}

function PendingCard({ item, onDone }) {
  const [comment, setComment] = useState('')
  const doc = item.document

  const approve = useMutation({
    mutationFn: () => validationsApi.approve(item.id, comment || undefined),
    onSuccess: (res) => {
      toast.success(res.message)
      setComment('')
      onDone()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })
  const reject = useMutation({
    mutationFn: () => {
      if (comment.trim().length < 3) {
        throw new Error('Un commentaire d’au moins 3 caractères est obligatoire pour rejeter.')
      }
      return validationsApi.reject(item.id, comment.trim())
    },
    onSuccess: (res) => {
      toast.success(res.message)
      setComment('')
      onDone()
    },
    onError: (e) => toast.error(getErrorMessage(e) || e.message),
  })
  const correction = useMutation({
    mutationFn: () => validationsApi.requestCorrection(item.id, comment || undefined),
    onSuccess: (res) => {
      toast.success(res.message)
      setComment('')
      onDone()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const busy = approve.isPending || reject.isPending || correction.isPending

  return (
    <li className="rounded-xl border border-border bg-background p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <Link to={`/documents/${doc?.id}?tab=validations`} className="font-medium hover:text-primary">
              {doc?.title ?? 'Document'}
            </Link>
            <Badge tone={item.is_overdue ? 'danger' : 'warning'}>
              {item.is_overdue ? 'En retard' : 'À valider'}
            </Badge>
          </div>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {doc?.reference}
            {item.workflow_step
              ? ` · Étape ${item.workflow_step.step_order} — ${item.workflow_step.name}`
              : ''}
            {item.workflow_step?.responsible_user?.name || item.workflow_step?.responsible_role?.name
              ? ` · ${item.workflow_step.responsible_user?.name ?? item.workflow_step.responsible_role?.name}`
              : ''}
            {doc?.project?.name ? ` · ${doc.project.name}` : ''}
            {item.due_at ? ` · échéance ${formatDate(item.due_at, true)}` : ''}
          </p>
        </div>
        <Button as={Link} to={`/documents/${doc?.id}?tab=validations`} size="sm" variant="secondary">
          <Eye className="h-4 w-4" />
          Fiche
        </Button>
      </div>
      <Input
        className="mt-3"
        placeholder="Commentaire (obligatoire pour un rejet)"
        value={comment}
        onChange={(e) => setComment(e.target.value)}
      />
      <div className="mt-3 flex flex-wrap gap-2">
        <Button size="sm" disabled={busy} onClick={() => approve.mutate()}>
          <Check className="h-4 w-4" />
          Approuver
        </Button>
        <Button size="sm" variant="secondary" disabled={busy} onClick={() => correction.mutate()}>
          Correction
        </Button>
        <Button size="sm" variant="danger" disabled={busy} onClick={() => reject.mutate()}>
          <X className="h-4 w-4" />
          Rejeter
        </Button>
      </div>
    </li>
  )
}
