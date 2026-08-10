import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Eye, Play } from 'lucide-react'
import { toast } from 'sonner'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate, statusLabel } from '@/lib/format'
import { canAny } from '@/lib/permissions'
import { queryKeys } from '@/lib/queryClient'
import { documentsApi } from '@/modules/documents/api'
import { validationsApi } from '@/modules/validations/api'
import { useAuthStore } from '@/stores/authStore'

export default function ProposedDocumentsPage() {
  const user = useAuthStore((s) => s.user)
  const queryClient = useQueryClient()
  const canManage = canAny(user, ['workflows.manage', 'projects.manage'])

  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.documents({ status: 'propose' }),
    queryFn: () => documentsApi.list({ status: 'propose', per_page: 50 }),
    enabled: canManage,
  })

  const startWorkflow = useMutation({
    mutationFn: (doc) => validationsApi.start(doc.id, doc.workflow?.id ?? null),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['documents'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  if (!canManage) {
    return (
      <EmptyState
        title="Accès refusé"
        description="Seuls les responsables workflow / projet peuvent traiter les propositions."
      />
    )
  }

  const documents = unwrapPaginated(data).data

  return (
    <>
      <PageHeader
        title="Documents proposés"
        description="Propositions en attente. Le workflow reste optionnel : un document peut être traité hors validation."
      />

      {isLoading ? (
        <LoadingScreen label="Propositions…" />
      ) : isError ? (
        <EmptyState title="Impossible de charger les propositions" />
      ) : documents.length === 0 ? (
        <EmptyState
          title="Aucune proposition en attente"
          description="Les documents proposés par les collaborateurs apparaîtront ici."
        />
      ) : (
        <ul className="divide-y divide-border rounded-xl border border-border bg-background">
          {documents.map((doc) => (
            <li key={doc.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <Link to={`/documents/${doc.id}`} className="font-medium hover:text-primary">
                    {doc.title}
                  </Link>
                  <Badge tone="warning">{statusLabel(doc.status)}</Badge>
                  {doc.recommends_workflow ? (
                    <Badge tone="neutral">Workflow recommandé</Badge>
                  ) : null}
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
                  disabled={!doc.workflow?.id || startWorkflow.isPending}
                  title={
                    doc.workflow?.id
                      ? 'Démarrer le workflow associé'
                      : 'Aucun workflow associé — ouvrez la fiche pour en choisir un'
                  }
                  onClick={() => startWorkflow.mutate(doc)}
                >
                  <Play className="h-4 w-4" />
                  Démarrer
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}
