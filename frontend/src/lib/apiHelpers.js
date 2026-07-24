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
