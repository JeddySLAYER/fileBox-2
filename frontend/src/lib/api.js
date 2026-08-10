import axios from 'axios'
import { useAuthStore } from '@/stores/authStore'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const data = error.response?.data
    const message = data?.message

    // Middleware password.changed → forcer le changement de mot de passe
    if (status === 403 && typeof message === 'string' && message.toLowerCase().includes('mot de passe')) {
      useAuthStore.getState().setMustChangePassword(true)
    }

    // Compte désactivé (middleware active) → fin de session
    if (status === 401 && (data?.account_disabled || (typeof message === 'string' && message.toLowerCase().includes('désactivé')))) {
      useAuthStore.getState().clearSession()
      if (window.location.pathname !== '/login') {
        window.location.assign('/login')
      }
      return Promise.reject(error)
    }

    if (status === 401) {
      useAuthStore.getState().clearSession()
      if (window.location.pathname !== '/login') {
        window.location.assign('/login')
      }
    }

    return Promise.reject(error)
  },
)

export default api

export function getErrorMessage(error, fallback = 'Une erreur est survenue.') {
  const data = error?.response?.data
  if (!data) return fallback

  if (data.errors && typeof data.errors === 'object') {
    const first = Object.values(data.errors).flat()[0]
    if (first) {
      const raw = String(first)
      // Laravel renvoie parfois la clé de traduction brute (locale FR absente)
      if (raw === 'validation.uploaded' || raw.endsWith('validation.uploaded')) {
        return 'Échec de l’envoi du fichier (taille max PHP, connexion interrompue, ou fichier invalide).'
      }
      return raw
    }
  }

  if (data.message) {
    const msg = String(data.message)
    if (msg === 'validation.uploaded') {
      return 'Échec de l’envoi du fichier (taille max PHP, connexion interrompue, ou fichier invalide).'
    }
    return msg
  }
  return fallback
}
