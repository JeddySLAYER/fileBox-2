import api from '@/lib/api'

export const backupsApi = {
  list: () => api.get('/backups').then((r) => r.data),
  create: (notes) => api.post('/backups', { notes }).then((r) => r.data),
  restore: (id) => api.post(`/backups/${id}/restore`).then((r) => r.data),
  remove: (id) => api.delete(`/backups/${id}`).then((r) => r.data),
  download: async (id, fileName = 'backup.zip') => {
    const response = await api.get(`/backups/${id}/download`, { responseType: 'blob' })
    const url = window.URL.createObjectURL(response.data)
    const a = document.createElement('a')
    a.href = url
    a.download = fileName
    a.click()
    window.URL.revokeObjectURL(url)
  },
}
