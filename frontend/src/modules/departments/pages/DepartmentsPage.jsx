import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Plus, Trash2 } from 'lucide-react'
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
import { unwrapPaginated } from '@/lib/apiHelpers'
import { formatDate } from '@/lib/format'
import { queryKeys } from '@/lib/queryClient'
import { departmentsApi } from '@/modules/departments/api'

export default function DepartmentsPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ name: '', code: '', description: '' })

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.departments({ search, per_page: 50 }),
    queryFn: () => departmentsApi.list({ search: search || undefined, per_page: 50 }),
  })

  const createDept = useMutation({
    mutationFn: () =>
      departmentsApi.create({
        name: form.name,
        code: form.code || undefined,
        description: form.description || undefined,
      }),
    onSuccess: (res) => {
      toast.success(res.message)
      setShowForm(false)
      setForm({ name: '', code: '', description: '' })
      queryClient.invalidateQueries({ queryKey: ['departments'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeDept = useMutation({
    mutationFn: (id) => departmentsApi.remove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: ['departments'] })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const { data: departments, meta } = unwrapPaginated(data)

  return (
    <RequirePermission permission="departments.manage">
      <PageHeader
        title="Départements"
        description="Organisation (API /departments)."
        actions={
          <Button size="sm" onClick={() => setShowForm((v) => !v)}>
            <Plus className="h-4 w-4" />
            Nouveau
          </Button>
        }
      />

      <Input
        className="mb-4 max-w-sm"
        placeholder="Rechercher…"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />

      {showForm ? (
        <Card className="mb-6">
          <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
              e.preventDefault()
              createDept.mutate()
            }}
          >
            <div>
              <Label htmlFor="d-name">Nom</Label>
              <Input
                id="d-name"
                required
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
            </div>
            <div>
              <Label htmlFor="d-code">Code</Label>
              <Input
                id="d-code"
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="d-desc">Description</Label>
              <Input
                id="d-desc"
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
              />
            </div>
            <div className="flex gap-2 sm:col-span-2">
              <Button type="submit" disabled={createDept.isPending}>
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
      ) : departments.length === 0 ? (
        <EmptyState title="Aucun département" />
      ) : (
        <div className="grid gap-3">
          {departments.map((dept) => (
            <Card key={dept.id} className="flex items-start justify-between gap-4">
              <div>
                <div className="flex items-center gap-2">
                  <Building2 className="h-4 w-4 text-primary" />
                  <p className="font-medium">{dept.name}</p>
                  {dept.code ? (
                    <span className="text-xs text-muted-foreground">{dept.code}</span>
                  ) : null}
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                  {dept.users_count ?? 0} utilisateur(s) · {dept.projects_count ?? 0} projet(s) ·{' '}
                  {formatDate(dept.created_at)}
                </p>
                {dept.description ? (
                  <p className="mt-2 text-sm text-muted-foreground">{dept.description}</p>
                ) : null}
              </div>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  if (window.confirm(`Supprimer ${dept.name} ?`)) removeDept.mutate(dept.id)
                }}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </Card>
          ))}
          {meta ? (
            <p className="text-xs text-muted-foreground">{meta.total} département(s)</p>
          ) : null}
        </div>
      )}
    </RequirePermission>
  )
}
