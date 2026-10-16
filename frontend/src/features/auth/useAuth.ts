import { createContext, useContext } from 'react'
import type { User } from '../../types/api'

export interface LoginInput {
  email: string
  password: string
}

export interface RegisterInput extends LoginInput {
  name: string
  password_confirmation: string
}

export interface AuthApi {
  user: User | null
  isLoading: boolean
  login: (input: LoginInput) => Promise<User>
  register: (input: RegisterInput) => Promise<User>
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthApi | null>(null)

export const meQueryKey = ['auth', 'me'] as const

export function useAuth(): AuthApi {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used inside <AuthProvider>')
  }
  return context
}
