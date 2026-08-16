import { useState } from 'react'
import { toast } from 'sonner'
import Button from '@/components/ui/Button'
import Card from '@/components/ui/Card'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import PageHeader from '@/components/ui/PageHeader'
import { getErrorMessage } from '@/lib/api'
import { authApi } from '@/modules/auth/api'
import { useAuthStore } from '@/stores/authStore'

export default function ProfilePage() {
  const user = useAuthStore((s) => s.user)
  const setUser = useAuthStore((s) => s.setUser)
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [savingProfile, setSavingProfile] = useState(false)

  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [savingPassword, setSavingPassword] = useState(false)

  const roles = (user?.roles ?? []).map((r) => r.name).join(', ') || '—'

  async function saveProfile(e) {
    e.preventDefault()
    setSavingProfile(true)
    try {
      const data = await authApi.updateProfile({ name: name.trim(), email: email.trim() })
      setUser(data.user)
      toast.success(data.message ?? 'Profil mis à jour.')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Impossible de mettre à jour le profil.'))
    } finally {
      setSavingProfile(false)
    }
  }

  async function savePassword(e) {
    e.preventDefault()
    if (password !== passwordConfirmation) {
      toast.error('La confirmation ne correspond pas.')
      return
    }
    setSavingPassword(true)
    try {
      const data = await authApi.changePassword({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      })
      setUser(data.user)
      setCurrentPassword('')
      setPassword('')
      setPasswordConfirmation('')
      toast.success(data.message ?? 'Mot de passe mis à jour.')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Impossible de changer le mot de passe.'))
    } finally {
      setSavingPassword(false)
    }
  }

  return (
    <>
      <PageHeader
        title="Profil"
        description="Modifiez vos informations de compte. Le rôle et le département sont gérés par un administrateur."
      />

      <div className="grid max-w-2xl gap-4">
        <Card>
          <h2 className="text-sm font-semibold">Identité</h2>
          <form className="mt-4 space-y-4" onSubmit={saveProfile}>
            <div>
              <Label htmlFor="name">Nom</Label>
              <Input
                id="name"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </div>
            <div>
              <Label htmlFor="email">E-mail</Label>
              <Input
                id="email"
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label>Rôle</Label>
                <p className="text-sm text-muted-foreground">{roles}</p>
              </div>
              <div>
                <Label>Département</Label>
                <p className="text-sm text-muted-foreground">{user?.department?.name ?? '—'}</p>
              </div>
            </div>
            <Button type="submit" size="sm" disabled={savingProfile}>
              {savingProfile ? 'Enregistrement…' : 'Enregistrer'}
            </Button>
          </form>
        </Card>

        <Card>
          <h2 className="text-sm font-semibold">Mot de passe</h2>
          <form className="mt-4 space-y-4" onSubmit={savePassword}>
            <div>
              <Label htmlFor="current">Mot de passe actuel</Label>
              <Input
                id="current"
                type="password"
                required
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
              />
            </div>
            <div>
              <Label htmlFor="next">Nouveau mot de passe</Label>
              <Input
                id="next"
                type="password"
                required
                minLength={8}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </div>
            <div>
              <Label htmlFor="confirm">Confirmation</Label>
              <Input
                id="confirm"
                type="password"
                required
                minLength={8}
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
              />
            </div>
            <Button type="submit" size="sm" disabled={savingPassword}>
              {savingPassword ? 'Enregistrement…' : 'Changer le mot de passe'}
            </Button>
          </form>
        </Card>
      </div>
    </>
  )
}
