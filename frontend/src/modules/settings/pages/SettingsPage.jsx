import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Save } from 'lucide-react'
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
import { unwrapList } from '@/lib/apiHelpers'
import { queryKeys } from '@/lib/queryClient'
import { settingsApi } from '@/modules/settings/api'

export default function SettingsPage() {
  const queryClient = useQueryClient()
  const [values, setValues] = useState({})

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.settings,
    queryFn: settingsApi.list,
  })

  const settings = unwrapList(data)

  useEffect(() => {
    if (settings.length === 0) return
    const map = {}
    settings.forEach((s) => {
      map[s.key] = s.value ?? ''
    })
    setValues(map)
  }, [data])

  const saveBulk = useMutation({
    mutationFn: () => {
      const payload = {}
      settings.forEach((s) => {
        payload[s.key] = {
          value: values[s.key],
          type: s.type,
          description: s.description,
        }
      })
      return settingsApi.bulk(payload)
    },
    onSuccess: (res) => {
      toast.success(res.message)
      queryClient.invalidateQueries({ queryKey: queryKeys.settings })
    },
    onError: (e) => toast.error(getErrorMessage(e)),
  })

  return (
    <RequirePermission permission="settings.manage">
      <PageHeader
        title="Paramètres système"
        description="Configuration FileBox (API /settings)."
        actions={
          <Button size="sm" onClick={() => saveBulk.mutate()} disabled={saveBulk.isPending}>
            <Save className="h-4 w-4" />
            Enregistrer
          </Button>
        }
      />

      {isLoading ? (
        <LoadingScreen />
      ) : settings.length === 0 ? (
        <EmptyState title="Aucun paramètre" description="Lancez le seeder SystemSettingSeeder." />
      ) : (
        <div className="grid gap-3">
          {settings.map((setting) => (
            <Card key={setting.key}>
              <Label htmlFor={setting.key}>{setting.key}</Label>
              {setting.description ? (
                <p className="mb-2 text-xs text-muted-foreground">{setting.description}</p>
              ) : null}
              {setting.type === 'boolean' ? (
                <label className="flex items-center gap-2 text-sm">
                  <input
                    id={setting.key}
                    type="checkbox"
                    checked={values[setting.key] === '1' || values[setting.key] === true}
                    onChange={(e) =>
                      setValues({
                        ...values,
                        [setting.key]: e.target.checked ? '1' : '0',
                      })
                    }
                  />
                  Activé
                </label>
              ) : (
                <Input
                  id={setting.key}
                  type={setting.type === 'integer' ? 'number' : 'text'}
                  value={values[setting.key] ?? ''}
                  onChange={(e) => setValues({ ...values, [setting.key]: e.target.value })}
                />
              )}
            </Card>
          ))}
        </div>
      )}
    </RequirePermission>
  )
}
