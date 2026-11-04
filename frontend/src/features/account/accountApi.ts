import { api } from '../../lib/api'
import type { User } from '../../types/api'

export interface ProfileInput {
  first_name: string
  last_name: string
  email: string
  /** Needed only when the email changes. */
  current_password?: string
}

export async function updateProfile(input: ProfileInput) {
  const { data } = await api.put<{ data: User }>('/api/me/profile', input)
  return data.data
}

export interface PasswordInput {
  current_password: string
  password: string
  password_confirmation: string
}

export async function updatePassword(input: PasswordInput) {
  await api.put('/api/me/password', input)
}
