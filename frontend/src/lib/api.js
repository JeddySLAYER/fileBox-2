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
  if (!error?.response) {
    const raw = String(error?.message ?? '')
    if (!raw || raw === 'Network Error' || error?.code === 'ERR_NETWORK') {
      return 'Impossible de joindre le serveur. Vérifiez votre connexion.'
    }
    if (raw.startsWith('timeout') || error?.code === 'ECONNABORTED') {
      return 'Le serveur met trop de temps à répondre. Réessayez.'
    }
    return raw || fallback
  }

  const status = error.response.status
  const data = error.response.data
  if (!data) {
    if (status === 403) return 'Vous n’avez pas l’autorisation d’effectuer cette action.'
    if (status === 404) return 'Cet élément est introuvable ou a été supprimé.'
    if (status >= 500) return 'Une erreur interne s’est produite. Réessayez dans un instant.'
    return fallback
  }

  if (data.errors && typeof data.errors === 'object') {
    const first = Object.values(data.errors).flat()[0]
    if (first) {
      const raw = String(first)
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
    if (msg === 'The given data was invalid.') {
      return 'Certaines informations sont invalides. Vérifiez le formulaire.'
    }
    return msg
  }

  if (status === 403) return 'Vous n’avez pas l’autorisation d’effectuer cette action.'
  if (status === 404) return 'Cet élément est introuvable ou a été supprimé.'
  if (status >= 500) return 'Une erreur interne s’est produite. Réessayez dans un instant.'
  return fallback
}
