import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import Button from '@/components/ui/Button'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import { getErrorMessage } from '@/lib/api'
import { authApi } from '@/modules/auth/api'
import { useAuthStore } from '@/stores/authStore'

export default function LoginForm() {
  const navigate = useNavigate()
  const setSession = useAuthStore((s) => s.setSession)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)

  async function onSubmit(e) {
    e.preventDefault()
    setLoading(true)
    try {
      const data = await authApi.login({
        email,
        password,
        device_name: 'filebox-web',
      })

      setSession({
        token: data.token,
        user: data.user,
        mustChangePassword: data.must_change_password,
      })

      toast.success('Connexion réussie')

      if (data.must_change_password) {
        navigate('/change-password', { replace: true })
      } else {
        navigate('/dashboard', { replace: true })
      }
    } catch (error) {
      toast.error(getErrorMessage(error, 'Identifiants invalides.'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <form onSubmit={onSubmit} className="mt-8 space-y-4">
      <div>
        <Label htmlFor="email">Adresse email</Label>
        <Input
          id="email"
          type="email"
          autoComplete="username"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          placeholder="admin@filebox.local"
        />
      </div>
      <div>
        <Label htmlFor="password">Mot de passe</Label>
        <Input
          id="password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
      </div>
      <Button type="submit" className="w-full" disabled={loading}>
        {loading ? 'Connexion…' : 'Se connecter'}
      </Button>
    </form>
  )
}
