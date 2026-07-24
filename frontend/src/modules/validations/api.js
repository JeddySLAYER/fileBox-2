import api from '@/lib/api'

export const validationsApi = {
  listForDocument: (documentId) =>
    api.get(`/documents/${documentId}/validations`).then((r) => r.data),
  start: (documentId, workflowId) =>
    api.post(`/documents/${documentId}/workflow/start`, { workflow_id: workflowId }).then((r) => r.data),
  restart: (documentId) =>
    api.post(`/documents/${documentId}/workflow/restart`).then((r) => r.data),
  approve: (validationId, comment) =>
    api.post(`/validations/${validationId}/approve`, { comment }).then((r) => r.data),
  reject: (validationId, comment) =>
    api.post(`/validations/${validationId}/reject`, { comment }).then((r) => r.data),
  requestCorrection: (validationId, comment) =>
    api.post(`/validations/${validationId}/request-correction`, { comment }).then((r) => r.data),
}
