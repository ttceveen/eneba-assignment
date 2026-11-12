import { useEffect, useRef, useState, type SubmitEvent } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router'
import { authenticate, AuthError } from '../../api/auth'
import { useAuth } from '../../auth/context'
import styles from './Auth.module.css'

export default function Auth({ mode }: { mode: 'login' | 'register' }) {
  const { user, loading, signedIn } = useAuth()
  const [params] = useSearchParams()
  const raw = params.get('redirect')
  const destination = raw && ['/checkout', '/orders'].includes(raw.split('?')[0]) ? raw : '/'
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const pending = useRef<AbortController | null>(null)
  useEffect(() => () => pending.current?.abort(), [])
  if (!loading && user) return <Navigate to={destination} replace />
  async function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (busy) return
    const form = new FormData(event.currentTarget)
    const data = { email: String(form.get('email')), password: String(form.get('password')),
      ...(mode === 'register' ? { first_name: String(form.get('first_name')), last_name: String(form.get('last_name')) } : {}) }
    const controller = new AbortController(); pending.current = controller
    setBusy(true); setMessage(''); setErrors({})
    try {
      const result = await authenticate(mode, data, controller.signal)
      if (controller.signal.aborted) return
      signedIn(result)
      navigate(destination, { replace: true })
    } catch (error) {
      if (controller.signal.aborted) return
      setMessage(error instanceof AuthError ? error.message : 'Unable to sign in. Please try again.')
      if (error instanceof AuthError) setErrors(error.errors)
    } finally { if (!controller.signal.aborted) setBusy(false) }
  }
  const fields = mode === 'register' ? ['first_name', 'last_name', 'email', 'password'] : ['email', 'password']
  const labels: Record<string, string> = { first_name: 'First name', last_name: 'Last name', email: 'Email', password: 'Password' }
  const title = mode === 'register' ? 'Create account' : 'Log in'
  return <main className={styles.main}><section className={styles.panel}>
    <h1>{title}</h1>
    <form onSubmit={submit}>
      {fields.map((field) => <div className={styles.field} key={field}>
        <label htmlFor={field}>{labels[field]}</label>
        <input id={field} name={field} required type={field === 'password' ? 'password' : field === 'email' ? 'email' : 'text'}
          autoComplete={field === 'password' ? mode === 'register' ? 'new-password' : 'current-password' : field === 'first_name' ? 'given-name' : field === 'last_name' ? 'family-name' : 'email'}
          maxLength={field === 'email' ? 255 : field === 'password' ? 72 : 100} minLength={field === 'password' && mode === 'register' ? 8 : undefined}
          aria-invalid={Boolean(errors[field])} aria-describedby={errors[field] ? `${field}-error` : undefined} disabled={busy} />
        {errors[field] && <p id={`${field}-error`} className={styles.error}>{errors[field].join(' ')}</p>}
      </div>)}
      {message && <p role="alert" className={styles.error}>{message}</p>}
      <button className={styles.primary} type="submit" disabled={busy || loading}>{busy ? 'Please wait...' : title}</button>
    </form>
    <Link to={`/${mode === 'register' ? 'login' : 'register'}${raw ? `?redirect=${encodeURIComponent(destination)}` : ''}`}>{mode === 'register' ? 'Already have an account? Log in' : 'Create an account'}</Link>
  </section></main>
}
