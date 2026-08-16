import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Play, RotateCcw, Send, X } from 'lucide-react'
import { toast } from 'sonner'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import { getErrorMessage } from '@/lib/api'
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
import { partsFromHours } from '@/lib/duration'
import { formatDate, validationStatusLabel } from '@/lib/format'
import { canAny } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { documentsApi } from '@/modules/documents/api'
import { validationsApi } from '@/modules/validations/api'
import { workflowsApi } from '@/modules/workflows/api'
import { useAuthStore } from '@/stores/authStore'

function statusTone(status) {
  if (status === 'approuve') return 'success'
  if (status === 'en_attente') return 'warning'
  if (status === 'rejete') return 'danger'
  return 'neutral'
}

function canActOnStep(user, validation) {
  const step = validation?.workflow_step
  if (!step || !user) return false
  if (step.responsible_user?.id === user.id) return true
  if (step.responsible_role?.id && user.roles?.some((r) => r.id === step.responsible_role.id)) {
    return true
  }
  return false
}

function formatSla(hours) {
  if (hours == null) return null
  if (hours % 24 === 0) return `${hours / 24} j`
  return `${hours} h`
}

export default function DocumentValidationsPanel({
  documentId,
  documentStatus,
  documentWorkflow,
  subjectToWorkflow = false,
  recommendsWorkflow = false,
  canPropose = false,
  canAcceptProposition = false,
  requiresWorkflow = false,
  canStartWorkflow = false,
  onUpdated,
}) {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const [workflowId, setWorkflowId] = useState(documentWorkflow?.id ? String(documentWorkflow.id) : '')
  const [comment, setComment] = useState('')
  const [deadlineOverrides, setDeadlineOverrides] = useState({})

  const canStart = canAny(user, ['workflows.manage', 'projects.manage'])

  const validationsQuery = useQuery({
    queryKey: queryKeys.documentValidations(documentId),
    queryFn: () => validationsApi.listForDocument(documentId),
  })

  const workflowsQuery = useQuery({
    queryKey: queryKeys.workflows({ is_active: 1 }),
    queryFn: () => workflowsApi.list({ is_active: 1, per_page: 50 }),
    enabled: canStart,
  })

  const effectiveWfId = workflowId || (documentWorkflow?.id ? String(documentWorkflow.id) : '')

  const showPropose =
    subjectToWorkflow && canPropose && ['brouillon', 'rejete'].includes(documentStatus)

  const validations = unwrapList(validationsQuery.data)
  const showStart =
    canStart &&
    canStartWorkflow &&
    validations.length === 0 &&
    documentStatus !== 'en_validation'

  const showAccept = canStart && canAcceptProposition && documentStatus === 'propose'

  const selectedWorkflowQuery = useQuery({
    queryKey: queryKeys.workflow(effectiveWfId),
    queryFn: () => workflowsApi.get(effectiveWfId),
    enabled: Boolean(canStart && showStart && effectiveWfId),
  })

  const defaultDeadlines = useMemo(
    () =>
      Object.fromEntries(
        (selectedWorkflowQuery.data?.steps ?? []).map((s) => [
          s.id,
          partsFromHours(s.duration_hours),
        ]),
      ),
    [selectedWorkflowQuery.data?.steps],
  )

  const deadlines = { ...defaultDeadlines, ...deadlineOverrides }

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.documentValidations(documentId) })
    queryClient.invalidateQueries({ queryKey: queryKeys.document(documentId) })
    queryClient.invalidateQueries({ queryKey: queryKeys.validationsInbox })
    queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
    onUpdated?.()
  }

  const proposeDocument = useMutation({
    mutationFn: () => documentsApi.propose(documentId),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidate()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const acceptProposition = useMutation({
    mutationFn: () => documentsApi.acceptProposition(documentId),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidate()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const startWorkflow = useMutation({
    mutationFn: () => {
      const payload = Object.entries(deadlines)
        .filter(([, d]) => d?.amount && Number(d.amount) >= 1)
        .map(([stepId, d]) => ({
          workflow_step_id: Number(stepId),
          amount: Number(d.amount),
          unit: d.unit,
        }))
      return validationsApi.start(
        documentId,
        workflowId ? Number(workflowId) : null,
        payload,
      )
    },
    onSuccess: (res) => {
      toast.success(res.message)
      invalidate()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const restartWorkflow = useMutation({
    mutationFn: () => validationsApi.restart(documentId),
    onSuccess: (res) => {
      toast.success(res.message)
      invalidate()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const approve = useMutation({
    mutationFn: (validationId) => validationsApi.approve(validationId, comment || undefined),
    onSuccess: (res) => {
      toast.success(res.message)
      setComment('')
      invalidate()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const reject = useMutation({
    mutationFn: (validationId) => {
      const motif = comment.trim()
      if (motif.length < 3) {
        throw new Error('Un commentaire d’au moins 3 caractères est obligatoire pour rejeter.')
      }
      return validationsApi.reject(validationId, motif)
    },
    onSuccess: (res) => {
      toast.success(res.message)
      setComment('')
      invalidate()
    },
    onError: (e) => toast.error(getErrorMessage(e) || e.message),
  })

  const requestCorrection = useMutation({
    mutationFn: (validationId) =>
      validationsApi.requestCorrection(validationId, comment || undefined),
    onSuccess: (res) => {
      toast.success(res.message)
      setComment('')
      invalidate()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const workflows = unwrapPaginated(workflowsQuery.data).data
  const workflowSteps = selectedWorkflowQuery.data?.steps ?? []
  const currentPending = [...validations]
    .filter((v) => v.status === 'en_attente')
    .sort((a, b) => (a.workflow_step?.step_order ?? 0) - (b.workflow_step?.step_order ?? 0))[0]
  const canAct = currentPending ? canActOnStep(user, currentPending) : false

  if (validationsQuery.isLoading) return <LoadingScreen label="Validations…" />

  return (
    <div>
      {subjectToWorkflow && documentStatus === 'brouillon' ? (
        <p className="mb-4 text-sm text-muted-foreground">
          {requiresWorkflow
            ? 'Ce type exige un circuit : le workflow par défaut démarre automatiquement au dépôt.'
            : 'Les collaborateurs proposent automatiquement leurs dépôts. Un responsable peut accepter sans circuit ou assigner un workflow.'}
          {recommendsWorkflow && !requiresWorkflow ? ' Un workflow est recommandé pour ce type.' : ''}
        </p>
      ) : null}

      {subjectToWorkflow && documentStatus === 'en_validation' ? (
        <p className="mb-4 text-sm text-muted-foreground">
          {canAct
            ? 'C’est votre étape : approuvez, demandez une correction ou rejetez.'
            : 'Suivi du circuit : seules les personnes assignées à l’étape courante peuvent valider.'}
        </p>
      ) : null}

      {subjectToWorkflow && documentStatus === 'propose' ? (
        <p className="mb-4 text-sm text-muted-foreground">
          {canAny(user, ['workflows.manage', 'validations.act', 'projects.manage'])
            ? 'Proposition en attente : validez-la ou assignez un workflow. Ensuite vous suivez l’évolution ici ; seuls les assignés d’étape valident.'
            : 'Votre document est proposé. Vous pourrez suivre ici chaque étape du circuit une fois démarré.'}
        </p>
      ) : null}

      {showPropose ? (
        <div className="mb-4 rounded-lg border border-dashed border-border p-4">
          <p className="text-sm text-muted-foreground">
            {documentStatus === 'rejete'
              ? 'Après un rejet, déposez une nouvelle version puis reproposez le document pour relancer le circuit.'
              : 'Proposez le document pour qu&apos;un responsable l&apos;accepte ou démarre un workflow.'}
          </p>
          <Button
            className="mt-3"
            size="sm"
            disabled={proposeDocument.isPending}
            onClick={() => proposeDocument.mutate()}
          >
            <Send className="h-4 w-4" />
            {documentStatus === 'rejete' ? 'Reproposer à validation' : 'Proposer à validation'}
          </Button>
        </div>
      ) : null}

      {showAccept ? (
        <div className="mb-4 rounded-lg border border-dashed border-border p-4">
          <p className="text-sm text-muted-foreground">
            Accepter sans circuit de validation, ou assigner un workflow ci-dessous.
          </p>
          <Button
            className="mt-3"
            size="sm"
            variant="secondary"
            disabled={acceptProposition.isPending}
            onClick={() => acceptProposition.mutate()}
          >
            <Check className="h-4 w-4" />
            Valider la proposition
          </Button>
        </div>
      ) : null}

      {showStart ? (
        <div className="mb-4 space-y-3 rounded-lg border border-dashed border-border p-4">
          <div>
            <Label htmlFor="wf">Assigner un workflow</Label>
            <div className="mt-2 flex flex-wrap gap-2">
              <select
                id="wf"
                className="h-11 min-w-[200px] flex-1 rounded-lg border border-border bg-background px-3 text-sm"
                value={workflowId}
                onChange={(e) => {
                  setWorkflowId(e.target.value)
                  setDeadlineOverrides({})
                }}
              >
                <option value="">
                  {documentWorkflow?.name
                    ? `— ${documentWorkflow.name} (par défaut) —`
                    : '— Choisir un workflow —'}
                </option>
                {workflows.map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {effectiveWfId && workflowSteps.length > 0 ? (
            <div>
              <p className="text-sm font-medium">Délais par étape</p>
              <p className="mt-1 text-xs text-muted-foreground">
                Prérempli d’après le workflow. Modifiable au démarrage. Les rappels suivent la
                configuration de chaque étape.
              </p>
              <ul className="mt-3 space-y-2">
                {workflowSteps.map((step) => (
                  <li
                    key={step.id}
                    className="flex flex-wrap items-center gap-2 rounded-lg border border-border px-3 py-2"
                  >
                    <span className="min-w-[140px] flex-1 text-sm">
                      {step.step_order}. {step.name}
                      <span className="mt-0.5 block text-xs text-muted-foreground">
                        {step.responsible_role?.name ??
                          step.responsible_user?.name ??
                          'Responsable non défini'}
                      </span>
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
                            unit: prev[step.id]?.unit ?? defaultDeadlines[step.id]?.unit ?? 'days',
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
                            amount: prev[step.id]?.amount ?? defaultDeadlines[step.id]?.amount ?? '1',
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
            </div>
          ) : null}

          <Button
            size="sm"
            disabled={(!workflowId && !documentWorkflow?.id) || startWorkflow.isPending}
            onClick={() => startWorkflow.mutate()}
          >
            <Play className="h-4 w-4" />
            Lancer le circuit
          </Button>
        </div>
      ) : null}

      {documentStatus === 'brouillon' && validations.some((v) => v.status !== 'en_attente') ? (
        <Button
          className="mb-4"
          size="sm"
          variant="secondary"
          disabled={restartWorkflow.isPending}
          onClick={() => restartWorkflow.mutate()}
        >
          <RotateCcw className="h-4 w-4" />
          Réinitialiser le workflow
        </Button>
      ) : null}

      {validations.length === 0 ? (
        <EmptyState
          title="Aucune validation"
          description={
            subjectToWorkflow
              ? requiresWorkflow
                ? 'Ce type exige une validation. Proposez le document ou démarrez le workflow.'
                : canAny(user, ['workflows.manage', 'validations.act'])
                  ? 'Proposez le document pour qu’un responsable démarre le workflow.'
                  : 'Aucune étape ne vous est assignée pour le moment.'
              : 'Ce document n’est pas soumis à un circuit de validation.'
          }
        />
      ) : (
        <ul className="space-y-2">
          {validations.map((v) => (
            <li
              key={v.id}
              className="flex items-center justify-between gap-3 rounded-lg border border-border px-4 py-3"
            >
              <div>
                <p className="text-sm font-medium">
                  Étape {v.workflow_step?.step_order} — {v.workflow_step?.name}
                </p>
                <p className="text-xs text-muted-foreground">
                  {v.workflow_step?.responsible_role?.name ??
                    v.workflow_step?.responsible_user?.name ??
                    '—'}
                  {v.validated_at ? ` · ${formatDate(v.validated_at, true)}` : ''}
                </p>
                {v.sla_hours != null || v.due_at ? (
                  <p className="mt-1 text-xs text-muted-foreground">
                    {formatSla(v.sla_hours) ? `SLA ${formatSla(v.sla_hours)}` : null}
                    {v.due_at
                      ? ` · échéance ${formatDate(v.due_at, true)}${v.is_overdue ? ' (retard)' : ''}`
                      : v.sla_hours != null && v.status === 'en_attente'
                        ? ' · délai au démarrage de l’étape'
                        : ''}
                    {v.reminder_hours_before
                      ? ` · rappel ${formatSla(v.reminder_hours_before)} avant`
                      : ''}
                  </p>
                ) : null}
                {v.comment ? (
                  <p className="mt-1 text-xs italic text-muted-foreground">{v.comment}</p>
                ) : null}
              </div>
              <Badge tone={v.is_overdue ? 'danger' : statusTone(v.status)}>
                {v.is_overdue ? 'En retard' : validationStatusLabel(v.status)}
              </Badge>
            </li>
          ))}
        </ul>
      )}

      {currentPending && canAct ? (
        <div className="mt-4 rounded-lg border border-border bg-muted/30 p-4">
          <p className="text-sm font-medium">Action sur l&apos;étape courante</p>
          <p className="mt-1 text-xs text-muted-foreground">
            Commentaire obligatoire pour un rejet. Après correction, le soumetteur repropose puis un
            responsable relance le workflow.
          </p>
          <Input
            className="mt-2"
            placeholder="Commentaire (obligatoire si rejet)"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
          />
          <div className="mt-3 flex flex-wrap gap-2">
            <Button size="sm" onClick={() => approve.mutate(currentPending.id)}>
              <Check className="h-4 w-4" />
              Approuver
            </Button>
            <Button
              size="sm"
              variant="secondary"
              onClick={() => requestCorrection.mutate(currentPending.id)}
            >
              Correction
            </Button>
            <Button size="sm" variant="danger" onClick={() => reject.mutate(currentPending.id)}>
              <X className="h-4 w-4" />
              Rejeter
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
