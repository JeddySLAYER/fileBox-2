import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Tag, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import RequirePermission from '@/components/RequirePermission'
import Badge from '@/components/ui/Badge'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import EmptyState from '@/components/ui/EmptyState'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import LoadingScreen from '@/components/ui/LoadingScreen'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { unwrapList } from '@/lib/apiHelpers'
import { queryKeys } from '@/lib/queryClient'
import { tagsApi } from '@/modules/tags/api'

export default function TagsPage() {
  const queryClient = useQueryClient()
  const [name, setName] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.tags,
    queryFn: tagsApi.list,
  })

  const createTag = useMutation({
    mutationFn: () => tagsApi.create({ name }),
    onSuccess: (res) => {
      toast.success(res.message)
      setName('')
      queryClient.invalidateQueries({ queryKey: queryKeys.tags })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const removeTag = useMutation({
    mutationFn: (id) => tagsApi.remove(id),
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.tags })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  const tags = unwrapList(data)

  return (
    <RequirePermission permission="tags.manage">
      <PageHeader title="Tags" description="Étiquettes documentaires (API /tags)." />

      <Card className="mb-6">
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (!name.trim()) return
            createTag.mutate()
          }}
        >
          <div className="min-w-[200px] flex-1">
            <Label htmlFor="tag-name">Nouveau tag</Label>
            <Input
              id="tag-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Ex. contrat"
              required
            />
          </div>
          <Button type="submit" disabled={createTag.isPending}>
            <Plus className="h-4 w-4" />
            Ajouter
          </Button>
        </form>
      </Card>

      {isLoading ? (
        <LoadingScreen />
      ) : tags.length === 0 ? (
        <EmptyState title="Aucun tag" />
      ) : (
        <div className="flex flex-wrap gap-2">
          {tags.map((tag) => (
            <div
              key={tag.id}
              className="flex items-center gap-2 rounded-lg border border-border bg-background px-3 py-2"
            >
              <Tag className="h-3.5 w-3.5 text-primary" />
              <span className="text-sm font-medium">{tag.name}</span>
              <Badge>{tag.documents_count ?? 0}</Badge>
              <button
                type="button"
                className="text-muted-foreground hover:text-accent-foreground"
                onClick={() => {
                  if (window.confirm(`Supprimer « ${tag.name} » ?`)) removeTag.mutate(tag.id)
                }}
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </div>
          ))}
        </div>
      )}
    </RequirePermission>
  )
}
