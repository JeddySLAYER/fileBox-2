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
    const message = error.response?.data?.message

    // Middleware password.changed → forcer le changement de mot de passe
    if (status === 403 && typeof message === 'string' && message.toLowerCase().includes('mot de passe')) {
      useAuthStore.getState().setMustChangePassword(true)
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
    if (first) return String(first)
  }

  if (data.message) return String(data.message)
  return fallback
}
