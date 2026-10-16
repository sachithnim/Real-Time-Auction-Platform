import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { useMemo, type ReactNode } from 'react'
import { api, fetchCsrfCookie } from '../../lib/api'
import type { User } from '../../types/api'
import { AuthContext, meQueryKey, type AuthApi, type LoginInput, type RegisterInput } from './useAuth'


async function fetchMe(): Promise<User | null> {
  try {
    const { data } = await api.get<{ data: User }>('/api/auth/me')
    return data.data
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 401) {
      return null
    }
    throw error
  }
}

/** Session-cookie auth (Sanctum SPA): fetch the CSRF cookie, then log in. */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const me = useQuery({ queryKey: meQueryKey, queryFn: fetchMe, staleTime: 5 * 60_000 })

  const login = useMutation({
    mutationFn: async (input: LoginInput) => {
      await fetchCsrfCookie()
      const { data } = await api.post<{ data: User }>('/api/auth/login', input)
      return data.data
    },
    onSuccess: (user) => queryClient.setQueryData(meQueryKey, user),
  })

  const register = useMutation({
    mutationFn: async (input: RegisterInput) => {
      await fetchCsrfCookie()
      const { data } = await api.post<{ data: User }>('/api/auth/register', input)
      return data.data
    },
    onSuccess: (user) => queryClient.setQueryData(meQueryKey, user),
  })

  const logout = useMutation({
    mutationFn: () => api.post('/api/auth/logout'),
    onSettled: () => {
      // Drop everything cached for the previous user (e.g. "is_leading", own auto-bid).
      queryClient.clear()
      queryClient.setQueryData(meQueryKey, null)
    },
  })

  const value = useMemo<AuthApi>(
    () => ({
      user: me.data ?? null,
      isLoading: me.isLoading,
      login: login.mutateAsync,
      register: register.mutateAsync,
      logout: async () => {
        await logout.mutateAsync()
      },
    }),
    [me.data, me.isLoading, login.mutateAsync, register.mutateAsync, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
