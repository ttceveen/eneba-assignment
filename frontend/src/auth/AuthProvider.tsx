import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useLocation } from 'react-router'
import { AuthError, getMe, logout, type AuthResult } from '../api/auth'
import { AUTH_KEY, AuthContext, type AuthState } from './context'

export default function AuthProvider({ children }: { children: ReactNode }) {
  const location = useLocation()
  const checkKey = (location.pathname === '/checkout' || /^\/orders(?:\/|$)/.test(location.pathname)) ? location.key : 'public'
  const [state, setState] = useState<AuthState & { checkedKey: string }>({ user: null, loading: true, error: null, checkedKey: '' })
  const [revision, setRevision] = useState(0)
  const token = useRef<string | null>(null)
  const version = useRef(0)
  useEffect(() => {
    const controller = new AbortController()
    const current = ++version.current
    async function restore() {
      try {
        token.current = sessionStorage.getItem(AUTH_KEY)
        const user = token.current ? await getMe(token.current, controller.signal) : null
        if (!controller.signal.aborted && current === version.current) setState({ user, loading: false, error: null, checkedKey: checkKey })
      } catch (error) {
        if (controller.signal.aborted || current !== version.current) return
        if (error instanceof AuthError && [401, 403].includes(error.status)) {
          token.current = null
          try { sessionStorage.removeItem(AUTH_KEY) } catch { /* Storage may be unavailable. */ }
          setState({ user: null, loading: false, error: null, checkedKey: checkKey })
        } else setState({ user: null, loading: false, error: 'Unable to verify your account. Please retry.', checkedKey: checkKey })
      }
    }
    void restore()
    return () => controller.abort()
  }, [revision, checkKey])
  useEffect(() => {
    // Clean up only the obsolete localStorage credential; sessionStorage is authoritative.
    try { localStorage.removeItem(AUTH_KEY) } catch { /* Storage may be disabled. */ }
    const restore = () => setRevision((value) => value + 1)
    addEventListener('focus', restore)
    return () => removeEventListener('focus', restore)
  }, [])
  const signedIn = useCallback((result: AuthResult) => {
    sessionStorage.setItem(AUTH_KEY, result.token)
    ++version.current
    token.current = result.token
    setState({ user: result.data, loading: false, error: null, checkedKey: checkKey })
  }, [checkKey])
  const signOut = useCallback(async () => {
    ++version.current
    const current = token.current
    try { if (current) await logout(current, new AbortController().signal) }
    catch (error) {
      if (!(error instanceof AuthError) || ![401, 403].includes(error.status)) {
        // The previous check was invalidated above; failed logout must replace it.
        if (token.current === current) setRevision((value) => value + 1)
        throw error
      }
    }
    if (token.current !== current) return
    ++version.current
    try { sessionStorage.removeItem(AUTH_KEY) } catch { /* A revoked token cannot authenticate again. */ }
    token.current = null
    setState({ user: null, loading: false, error: null, checkedKey: checkKey })
    setRevision((value) => value + 1)
  }, [checkKey])
  const refresh = useCallback(() => setRevision((value) => value + 1), [])
  const value = useMemo(() => ({ ...state, loading: state.loading || state.checkedKey !== checkKey, signedIn, signOut, refresh }), [state, checkKey, signedIn, signOut, refresh])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
