import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import BrandMark from '@/components/BrandMark'
import Button from '@/components/ui/Button'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import { getErrorMessage } from '@/lib/api'
import { authApi } from '@/modules/auth/api'
import { useAuthStore } from '@/stores/authStore'

export default function ChangePasswordPage() {
  const navigate = useNavigate()
  const setUser = useAuthStore((s) => s.setUser)
  const setMustChangePassword = useAuthStore((s) => s.setMustChangePassword)
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [loading, setLoading] = useState(false)

  async function onSubmit(e) {
    e.preventDefault()
    if (password !== passwordConfirmation) {
      toast.error('La confirmation ne correspond pas.')
      return
    }

    setLoading(true)
    try {
      const data = await authApi.changePassword({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      })
      setUser(data.user)
      setMustChangePassword(false)
      toast.success('Mot de passe mis à jour')
      navigate('/dashboard', { replace: true })
    } catch (error) {
      toast.error(getErrorMessage(error, 'Impossible de changer le mot de passe.'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-muted px-4">
      <div className="w-full max-w-md rounded-2xl border border-border bg-background p-8 shadow-soft animate-fade-in">
        <div className="mb-6 flex items-center gap-3">
          <BrandMark />
          <div>
            <h1 className="text-xl font-semibold">Changer le mot de passe</h1>
            <p className="text-xs text-muted-foreground">
              Obligatoire à la première connexion ou après réinitialisation.
            </p>
          </div>
        </div>

        <form onSubmit={onSubmit} className="space-y-4">
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
          <Button type="submit" className="w-full" disabled={loading}>
            {loading ? 'Enregistrement…' : 'Enregistrer'}
          </Button>
        </form>
      </div>
    </div>
  )
}
