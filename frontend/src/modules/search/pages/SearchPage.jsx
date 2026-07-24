import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Search } from 'lucide-react'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { statusLabel } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { searchApi } from '@/modules/search/api'

export default function SearchPage() {
  const [q, setQ] = useState('')
  const [submitted, setSubmitted] = useState('')

  const { data, isFetching, isError } = useQuery({
    queryKey: queryKeys.search({ q: submitted, include_ocr: true }),
    queryFn: () =>
      searchApi.search({
        q: submitted,
        include_ocr: 1, // prêt pour OCR ultérieur
      }),
    enabled: submitted.length >= 2,
  })

  const documents = data?.documents?.data ?? []

  return (
    <>
      <PageHeader
        title="Recherche"
        description="Recherche multicritère. Le champ OCR sera peuplé automatiquement plus tard."
      />

      <form
        className="mb-6 flex flex-col gap-3 sm:flex-row"
        onSubmit={(e) => {
          e.preventDefault()
          setSubmitted(q.trim())
        }}
      >
        <div className="relative flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            className="pl-9"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Titre, référence, description, tag…"
          />
        </div>
        <Button type="submit">Rechercher</Button>
      </form>

      {!submitted ? (
        <EmptyState
          title="Lancez une recherche"
          description="Au moins 2 caractères. include_ocr est déjà activé côté API."
        />
      ) : isFetching ? (
        <LoadingScreen />
      ) : isError ? (
        <EmptyState title="Erreur de recherche" />
      ) : documents.length === 0 ? (
        <EmptyState title="Aucun résultat" description={`Rien pour « ${submitted} ».`} />
      ) : (
        <ul className="divide-y divide-border rounded-xl border border-border bg-background">
          {documents.map((doc) => (
            <li key={doc.id} className="flex items-center justify-between gap-3 px-4 py-3">
              <div className="min-w-0">
                <Link to={`/documents/${doc.id}`} className="font-medium hover:text-primary">
                  {doc.title}
                </Link>
                <p className="truncate text-xs text-muted-foreground">
                  {doc.reference} · {doc.folder?.name ?? '—'}
                </p>
              </div>
              <Badge>{statusLabel(doc.status)}</Badge>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}
