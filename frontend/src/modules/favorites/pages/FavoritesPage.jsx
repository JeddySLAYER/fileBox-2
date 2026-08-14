import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Star } from 'lucide-react'
import { toast } from 'sonner'
import EmptyState from '@/components/ui/EmptyState'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapList } from '@/lib/apiHelpers'
import { queryKeys } from '@/lib/queryClient'
import { favoritesApi } from '@/modules/favorites/api'

export default function FavoritesPage() {
  const queryClient = useQueryClient()
  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.favorites,
    queryFn: () => favoritesApi.list(),
  })
  const favorites = unwrapList(data)

  const remove = useMutation({
    mutationFn: (fav) =>
      fav.document
        ? favoritesApi.removeDocument(fav.document.id)
        : favoritesApi.removeFolder(fav.folder.id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.favorites })
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  if (isLoading) return <LoadingScreen />
  if (isError) return <EmptyState title="Impossible de charger les favoris" />

  return (
    <>
      <PageHeader title="Favoris" description="Documents et dossiers que vous avez épinglés." />

      {!favorites.length ? (
        <EmptyState
          title="Aucun favori"
          description="Ajoutez une étoile depuis l’explorateur ou une fiche document."
        />
      ) : (
        <ul className="divide-y divide-border rounded-xl border border-border bg-background">
          {favorites.map((fav) => (
            <li key={fav.id} className="flex items-center justify-between gap-3 px-4 py-3">
              <div className="min-w-0">
                {fav.document ? (
                  <>
                    <Link
                      to={`/documents/${fav.document.id}`}
                      className="font-medium hover:text-primary"
                    >
                      {fav.document.title}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                      {fav.document.reference}
                      {fav.document.folder?.name ? ` · ${fav.document.folder.name}` : ''}
                    </p>
                  </>
                ) : fav.folder ? (
                  <>
                    <Link
                      to={`/explorer?folder=${fav.folder.id}`}
                      className="font-medium hover:text-primary"
                    >
                      {fav.folder.name}
                    </Link>
                    <p className="text-xs text-muted-foreground">Dossier</p>
                  </>
                ) : null}
              </div>
              <button
                type="button"
                className="rounded-lg p-2 text-amber-500 hover:bg-muted"
                title="Retirer des favoris"
                disabled={remove.isPending}
                onClick={() => remove.mutate(fav)}
              >
                <Star className="h-4 w-4 fill-current" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}
