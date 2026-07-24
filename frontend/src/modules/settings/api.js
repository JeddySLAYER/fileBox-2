import api from '@/lib/api'

export const settingsApi = {
  list: () => api.get('/settings').then((r) => r.data),
  upsert: (payload) => api.put('/settings', payload).then((r) => r.data),
  bulk: (settings) => api.put('/settings/bulk', { settings }).then((r) => r.data),
}
