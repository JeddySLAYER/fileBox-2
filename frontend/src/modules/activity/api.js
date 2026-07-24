import api from '@/lib/api'

export const activityLogsApi = {
  list: (params = {}) => api.get('/activity-logs', { params }).then((r) => r.data),
  system: (lines = 100) =>
    api.get('/activity-logs/system', { params: { lines } }).then((r) => r.data.lines),
}
