import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Search } from 'lucide-react'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { unwrapList } from '@/lib/apiHelpers'
import { documentStatusLabels, statusLabel } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { departmentsApi } from '@/modules/departments/api'
import { documentTypesApi } from '@/modules/document-types/api'
import { projectsApi } from '@/modules/projects/api'
import { searchApi } from '@/modules/search/api'

const selectClass = 'h-11 w-full rounded-lg border border-border bg-background px-3 text-sm'

const emptyFilters = {
  project_id: '',
  department_id: '',
  status: '',
  document_type_id: '',
  confidentiality: '',
}

function buildParams(q, filters) {
  const params = { per_page: 30 }
  if (q) params.q = q
  if (filters.project_id) params.project_id = filters.project_id
  if (filters.department_id) params.department_id = filters.department_id
  if (filters.status) params.status = filters.status
  if (filters.document_type_id) params.document_type_id = filters.document_type_id
  if (filters.confidentiality) params.confidentiality = filters.confidentiality
  return params
}

function hasActiveCriteria(q, filters) {
  return q.length >= 2 || Object.values(filters).some(Boolean)
}

export default function SearchPage() {
  const [q, setQ] = useState('')
  const [filters, setFilters] = useState(emptyFilters)
  const [submitted, setSubmitted] = useState(null)

  const projectsQuery = useQuery({
    queryKey: queryKeys.projects({ per_page: 100 }),
    queryFn: () => projectsApi.list({ per_page: 100 }),
  })
  const departmentsQuery = useQuery({
    queryKey: queryKeys.departments({ per_page: 100 }),
    queryFn: () => departmentsApi.list({ per_page: 100 }),
  })
  const typesQuery = useQuery({
    queryKey: queryKeys.documentTypes({}),
    queryFn: () => documentTypesApi.list(),
  })

  const enabled = submitted != null && hasActiveCriteria(submitted.q, submitted.filters)

  const { data, isFetching, isError } = useQuery({
    queryKey: queryKeys.search(submitted ? buildParams(submitted.q, submitted.filters) : {}),
    queryFn: () => searchApi.search(buildParams(submitted.q, submitted.filters)),
    enabled,
  })

  const projects = unwrapList(projectsQuery.data)
  const departments = unwrapList(departmentsQuery.data)
  const types = unwrapList(typesQuery.data)
  const documents = data?.documents?.data ?? []
  const folders = unwrapList(data?.folders)

  const setFilter = (key, value) => setFilters((prev) => ({ ...prev, [key]: value }))

  return (
    <>
      <PageHeader
        title="Recherche"
        description="Recherche par texte et filtres (projet, département, statut, type…)."
      />

      <form
        className="mb-6 space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          const nextQ = q.trim()
          if (!hasActiveCriteria(nextQ, filters)) return
          setSubmitted({ q: nextQ, filters: { ...filters } })
        }}
      >
        <div className="flex flex-col gap-3 sm:flex-row">
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
        </div>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <Label>Projet</Label>
            <select
              className={selectClass}
              value={filters.project_id}
              onChange={(e) => setFilter('project_id', e.target.value)}
            >
              <option value="">Tous</option>
              {projects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label>Département</Label>
            <select
              className={selectClass}
              value={filters.department_id}
              onChange={(e) => setFilter('department_id', e.target.value)}
            >
              <option value="">Tous</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label>Statut</Label>
            <select
              className={selectClass}
              value={filters.status}
              onChange={(e) => setFilter('status', e.target.value)}
            >
              <option value="">Tous</option>
              {Object.entries(documentStatusLabels).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label>Type</Label>
            <select
              className={selectClass}
              value={filters.document_type_id}
              onChange={(e) => setFilter('document_type_id', e.target.value)}
            >
              <option value="">Tous</option>
              {types.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <Label>Confidentialité</Label>
            <select
              className={selectClass}
              value={filters.confidentiality}
              onChange={(e) => setFilter('confidentiality', e.target.value)}
            >
              <option value="">Toutes</option>
              <option value="public_interne">Public interne</option>
              <option value="restreint">Restreint</option>
              <option value="confidentiel">Confidentiel</option>
              <option value="tres_confidentiel">Très confidentiel</option>
            </select>
          </div>
        </div>
      </form>

      {!submitted ? (
        <EmptyState
          title="Lancez une recherche"
          description="Saisissez au moins 2 caractères ou choisissez un filtre."
        />
      ) : isFetching ? (
        <LoadingScreen />
      ) : isError ? (
        <EmptyState title="Erreur de recherche" />
      ) : documents.length === 0 && folders.length === 0 ? (
        <EmptyState title="Aucun résultat" />
      ) : (
        <div className="space-y-6">
          {documents.length > 0 ? (
            <ul className="divide-y divide-border rounded-xl border border-border bg-background">
              {documents.map((doc) => (
                <li key={doc.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <Link to={`/documents/${doc.id}`} className="font-medium hover:text-primary">
                      {doc.title}
                    </Link>
                    <p className="truncate text-xs text-muted-foreground">
                      {doc.reference} · {doc.folder?.name ?? '—'}
                      {doc.project?.name ? ` · ${doc.project.name}` : ''}
                    </p>
                  </div>
                  <Badge>{statusLabel(doc.status)}</Badge>
                </li>
              ))}
            </ul>
          ) : null}

          {folders.length > 0 ? (
            <div>
              <h2 className="mb-2 text-sm font-medium text-muted-foreground">Dossiers</h2>
              <ul className="divide-y divide-border rounded-xl border border-border bg-background">
                {folders.map((folder) => (
                  <li key={folder.id} className="px-4 py-3">
                    <Link to={`/explorer?folder=${folder.id}`} className="font-medium hover:text-primary">
                      {folder.name}
                    </Link>
                    <p className="text-xs text-muted-foreground">
                      {folder.documents_count ?? 0} document(s)
                    </p>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      )}
    </>
  )
}
