import type { User } from '../types/api'

/** Two letters for the avatar: first + last name, or the first two words of the full name. */
export function initials(user: Pick<User, 'first_name' | 'last_name' | 'name'>): string {
  const parts = user.first_name ? [user.first_name, user.last_name ?? ''] : user.name.trim().split(/\s+/)
  return parts
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
}
