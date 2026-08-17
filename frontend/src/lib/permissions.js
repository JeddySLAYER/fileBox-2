/**
 * Helpers RBAC côté UI (miroir des permissions seedées backend).
 * L'invité sans permission globale s'appuie sur les accès API.
 */

export function userPermissions(user) {
  if (!user) return []
  if (Array.isArray(user.permissions)) return user.permissions
  return []
}

export function can(user, permission) {
  return userPermissions(user).includes(permission)
}

export function canAny(user, permissions) {
  return permissions.some((p) => can(user, p))
}

export function hasRole(user, slug) {
  return Boolean(user?.roles?.some((r) => r.slug === slug))
}

/** Admin / direction : voient les espaces org (département / projet), pas les privés d’autrui. */
export function seesAllSpaces(user) {
  return hasRole(user, 'administrateur') || hasRole(user, 'direction')
}
