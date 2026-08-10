import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import Button from '@/components/ui/Button'
import Input from '@/components/ui/Input'
import Label from '@/components/ui/Label'
import { getErrorMessage } from '@/lib/api'
import { authApi } from '@/modules/auth/api'
import { useAuthStore } from '@/stores/authStore'

function parseLoginMeta(error) {
  const login = error?.response?.data?.login
  if (!login || typeof login !== 'object') return null
  return login
}

export default function LoginForm() {
  const navigate = useNavigate()
  const setSession = useAuthStore((s) => s.setSession)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [formError, setFormError] = useState('')
  const [loginMeta, setLoginMeta] = useState(null)

  async function onSubmit(e) {
    e.preventDefault()
    setLoading(true)
    setFormError('')
    setLoginMeta(null)

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
      const meta = parseLoginMeta(error)
      const message = getErrorMessage(error, 'Identifiants incorrects.')

      // Feedback sur le formulaire (pas de toast / info-bulle)
      setFormError(message)
      setLoginMeta(meta)
    } finally {
      setLoading(false)
    }
  }

  const showAttempts =
    loginMeta &&
    !loginMeta.locked &&
    typeof loginMeta.attempts_made === 'number' &&
    loginMeta.attempts_made >= 2 &&
    typeof loginMeta.attempts_remaining === 'number'

  const locked = Boolean(loginMeta?.locked)
  const retryMinutes = loginMeta?.retry_after_minutes

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
          placeholder="email@entreprise.com"
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

      {formError ? (
        <div
          role="alert"
          className="rounded-lg border border-accent-foreground/20 bg-accent px-3.5 py-3 text-sm text-accent-foreground"
        >
          <p className="font-medium">{formError}</p>
          {showAttempts ? (
            <p className="mt-1.5 text-accent-foreground/90">
              {loginMeta.attempts_remaining === 1
                ? '1 essai restant avant verrouillage.'
                : `${loginMeta.attempts_remaining} essais restants avant verrouillage.`}
            </p>
          ) : null}
          {locked && retryMinutes != null ? (
            <p className="mt-1.5 text-accent-foreground/90">
              Réessayez dans {retryMinutes} minute{retryMinutes > 1 ? 's' : ''}.
            </p>
          ) : null}
        </div>
      ) : null}

      <Button type="submit" className="w-full" disabled={loading}>
        {loading ? 'Connexion…' : 'Se connecter'}
      </Button>
    </form>
  )
}
