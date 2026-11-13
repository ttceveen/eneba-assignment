import { useCallback } from 'react'
import { AUTH_KEY, useAuth } from '../auth/context'
import { CheckoutError } from '../api/checkout'

export function useConnection() {
  const { refresh } = useAuth()
  return useCallback(async <T,>(operation: (token: string) => Promise<T>): Promise<T> => {
    const token = sessionStorage.getItem(AUTH_KEY)
    if (!token) { refresh(); throw new CheckoutError(401) }
    try { return await operation(token) }
    catch (error) {
      if (error instanceof CheckoutError && error.status === 401 && sessionStorage.getItem(AUTH_KEY) === token) {
        sessionStorage.removeItem(AUTH_KEY); refresh()
      }
      throw error
    }
  }, [refresh])
}
