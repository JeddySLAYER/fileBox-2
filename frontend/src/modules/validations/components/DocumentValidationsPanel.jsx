import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Play, RotateCcw, X } from 'lucide-react'
import { toast } from 'sonner'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import { unwrapList, unwrapPaginated } from '@/lib/apiHelpers'
import { getErrorMessage } from '@/lib/api'
import { formatDate, validationStatusLabel } from '@/lib/format'
import { canAny } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { validationsApi } from '@/modules/validations/api'
import { workflowsApi } from '@/modules/workflows/api'
import { useAuthStore } from '@/stores/authStore'

function statusTone(status) {
  if (status === 'approuve') return 'success'
  if (status === 'en_attente') return 'warning'
  if (status === 'rejete') return 'danger'
  return 'neutral'
}

export default function DocumentValidationsPanel({ documentId, documentStatus, onUpdated }) {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const [workflowId, setWorkflowId] = useState('')
  const [comment, setComment] = useState('')

  const canAct = canAny(user, ['validations.act', 'workflows.manage'])

  const validationsQuery = useQuery({
    queryKey: queryKeys.documentValidations(documentId),
    queryFn: () => validationsApi.listForDocument(documentId),
  })

  const workflowsQuery = useQuery({
    queryKey: queryKeys.workflows({ is_active: 1 }),
    queryFn: () => workflowsApi.list({ is_active: 1, per_page: 50 }),
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.documentValidations(documentId) })
    queryClient.invalidateQueries({ queryKey: queryKeys.document(documentId) })
    onUpdated?.()
  }

  const startWorkflow = useMutation({
    mutationFn: () => validationsApi.start(documentId, Number(workflowId)),
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
    mutationFn: (validationId) => validationsApi.reject(validationId, comment || undefined),
    onSuccess: (res) => {
      toast.success(res.message)
      setComment('')
      invalidate()
    },
    onError: (e) => toast.error(getErrorMessage(e)),
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

  const validations = unwrapList(validationsQuery.data)
  const workflows = unwrapPaginated(workflowsQuery.data).data

  const currentPending = validations.find((v) => v.status === 'en_attente')

  if (validationsQuery.isLoading) return <LoadingScreen label="Validations…" />

  return (
    <div>
      {validations.length === 0 && documentStatus !== 'en_validation' ? (
        <div className="mb-4 rounded-lg border border-dashed border-border p-4">
          <Label htmlFor="wf">Démarrer un workflow</Label>
          <div className="mt-2 flex flex-wrap gap-2">
            <select
              id="wf"
              className="h-11 min-w-[200px] flex-1 rounded-lg border border-border bg-background px-3 text-sm"
              value={workflowId}
              onChange={(e) => setWorkflowId(e.target.value)}
            >
              <option value="">— Choisir un workflow —</option>
              {workflows.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
            <Button
              size="sm"
              disabled={!workflowId || startWorkflow.isPending}
              onClick={() => startWorkflow.mutate()}
            >
              <Play className="h-4 w-4" />
              Démarrer
            </Button>
          </div>
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
          Relancer le workflow
        </Button>
      ) : null}

      {validations.length === 0 ? (
        <EmptyState title="Aucune validation" description="Démarrez un workflow pour valider ce document." />
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
                {v.comment ? (
                  <p className="mt-1 text-xs italic text-muted-foreground">{v.comment}</p>
                ) : null}
              </div>
              <Badge tone={statusTone(v.status)}>{validationStatusLabel(v.status)}</Badge>
            </li>
          ))}
        </ul>
      )}

      {currentPending && canAct ? (
        <div className="mt-4 rounded-lg border border-border bg-muted/30 p-4">
          <p className="text-sm font-medium">Action sur l&apos;étape courante</p>
          <Input
            className="mt-2"
            placeholder="Commentaire (optionnel)"
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
