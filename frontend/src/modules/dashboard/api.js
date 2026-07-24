import api from '@/lib/api'

export const dashboardApi = {
  overview: () => api.get('/dashboard').then((r) => r.data.dashboard),
}
