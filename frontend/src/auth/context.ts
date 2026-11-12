import { createContext, useContext } from 'react'
import type { AuthResult, User } from '../api/auth'
export const AUTH_KEY = 'eneba:auth-token'
export type AuthState = { user: User | null; loading: boolean; error: string | null }
export type AuthContextValue = AuthState & { signedIn: (result: AuthResult) => void; signOut: () => Promise<void>; refresh: () => void }
export const AuthContext = createContext<AuthContextValue | null>(null)
export function useAuth() {
  const value = useContext(AuthContext)
  if (!value) throw new Error('AuthProvider is required')
  return value
}
