import { useQuery } from '@tanstack/react-query'
import { Navigate, useParams } from 'react-router-dom'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import { queryKeys } from '@/lib/queryClient'
import { projectsApi } from '@/modules/projects/api'

/** Redirige vers le dossier racine du projet dans l'explorateur. */
export default function ProjectDetailPage() {
  const { id } = useParams()

  const { data: project, isLoading, isError } = useQuery({
    queryKey: queryKeys.project(id),
    queryFn: () => projectsApi.get(id),
    enabled: Boolean(id),
  })

  if (isLoading) return <LoadingScreen />
  if (isError || !project) return <EmptyState title="Projet introuvable" />

  const folderId = project.root_folder_id ?? project.root_folder?.id
  if (folderId) {
    return <Navigate to={`/explorer?folder=${folderId}`} replace />
  }

  return <Navigate to="/projects" replace />
}
