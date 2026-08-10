/** Extrait data[] d'une réponse Laravel Resource (paginée ou non). */
export function unwrapList(response) {
  if (!response) return []
  if (Array.isArray(response)) return response
  if (Array.isArray(response.data)) return response.data
  return []
}

/** Extrait { data, meta, links } d'une réponse paginée Laravel. */
export function unwrapPaginated(response) {
  if (!response) return { data: [], meta: null, links: null }
  return {
    data: unwrapList(response),
    meta: response.meta ?? null,
    links: response.links ?? null,
  }
}

/** Pagination côté client pour les listes non paginées par l’API. */
export function paginateClient(items, page = 1, perPage = 10) {
  const list = Array.isArray(items) ? items : []
  const total = list.length
  const lastPage = Math.max(1, Math.ceil(total / perPage) || 1)
  const current = Math.min(Math.max(1, page), lastPage)
  const start = (current - 1) * perPage
  const data = list.slice(start, start + perPage)

  return {
    data,
    meta: {
      current_page: current,
      last_page: lastPage,
      per_page: perPage,
      total,
      from: total === 0 ? 0 : start + 1,
      to: total === 0 ? 0 : start + data.length,
    },
  }
}

export const PAGE_SIZE = 10
