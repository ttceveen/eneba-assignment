import { Navigate, Outlet, useLocation } from 'react-router'
import { useAuth } from './context'
export default function RequireAuth() {
  const { user, loading, error, refresh } = useAuth()
  const location = useLocation()
  if (loading) return <main><p role="status">Checking your account...</p></main>
  if (!user && error) return <main><p role="alert">{error}</p><button type="button" onClick={refresh}>Retry account check</button></main>
  if (!user) return <Navigate to={`/login?redirect=${encodeURIComponent(location.pathname + location.search)}`} replace />
  return <Outlet />
}
