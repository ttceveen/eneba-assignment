import { useEffect, useRef, useState, type SubmitEvent } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router'
import { authenticate, AuthError } from '../../api/auth'
import { useAuth } from '../../auth/context'
import styles from './Auth.module.css'

function authErrorText(message: string, field?: string): string {
  if (message === 'The provided credentials are incorrect.') return 'Neteisingas el. pašto adresas arba slaptažodis.'
  if (message === 'The email has already been taken.') return 'Šis el. pašto adresas jau naudojamas.'
  if (message === 'This account is blocked.') return 'Ši paskyra užblokuota.'
  if (message === 'The password must not exceed 72 bytes.') return 'Slaptažodis negali viršyti 72 baitų.'
  if (field === 'email' && message.includes('valid email address')) return 'Įveskite galiojantį el. pašto adresą.'
  if (message.endsWith('field is required.')) return 'Užpildykite šį lauką.'
  const length = /must (be at least|not be greater than) (\d+) characters\./.exec(message)
  if (length) return length[1] === 'be at least'
    ? `Įveskite bent ${length[2]} simbolių.`
    : `Įveskite ne daugiau kaip ${length[2]} simbolių.`
  return field ? 'Patikrinkite šio lauko reikšmę.' : 'Patikrinkite įvestus duomenis ir bandykite dar kartą.'
}

export default function Auth({ mode }: { mode: 'login' | 'register' }) {
  const { user, loading, signedIn } = useAuth()
  const [params] = useSearchParams()
  const raw = params.get('redirect')
  const destination = raw && (['/checkout', '/orders', '/paypal/return'].includes(raw.split('?')[0]) || /^\/orders\/[1-9]\d*(?:\/success)?$/.test(raw.split('?')[0])) ? raw : '/'
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
      if (error instanceof AuthError) {
        const fieldErrors = Object.entries(error.errors)
        setErrors(Object.fromEntries(fieldErrors.map(([field, messages]) => [field, messages.map(value => authErrorText(value, field))])))
        setMessage(error.status === 403 ? 'Ši paskyra užblokuota.' : error.status === 429
          ? 'Per daug bandymų. Pabandykite vėliau.'
          : error.status === 422 ? authErrorText(fieldErrors[0]?.[1][0] ?? error.message)
            : 'Nepavyko prisijungti prie serverio. Bandykite dar kartą.')
      } else {
        setMessage(mode === 'register' ? 'Nepavyko sukurti paskyros. Bandykite dar kartą.' : 'Nepavyko prisijungti. Bandykite dar kartą.')
      }
    } finally { if (!controller.signal.aborted) setBusy(false) }
  }
  const fields = mode === 'register' ? ['first_name', 'last_name', 'email', 'password'] : ['email', 'password']
  const labels: Record<string, string> = { first_name: 'Vardas', last_name: 'Pavardė', email: 'El. paštas', password: 'Slaptažodis' }
  const title = mode === 'register' ? 'Sukurti paskyrą' : 'Prisijungti'
  return (
    <main className={styles.main}>
      <Link className={styles.home} to="/" aria-label="Eneba pradžia">
        <img src="/images/eneba-logo.svg" alt="Eneba" width="155" height="49" />
      </Link>
      <section className={styles.panel} aria-labelledby="auth-title">
        <h1 id="auth-title">{title}</h1>
        <form onSubmit={submit}>
          {fields.map((field) => <div className={styles.field} key={field}>
            <label htmlFor={field}>{labels[field]}</label>
            <input id={field} name={field} required type={field === 'password' ? 'password' : field === 'email' ? 'email' : 'text'}
              autoComplete={field === 'password' ? mode === 'register' ? 'new-password' : 'current-password' : field === 'first_name' ? 'given-name' : field === 'last_name' ? 'family-name' : 'email'}
              maxLength={field === 'email' ? 255 : field === 'password' ? 72 : 100} minLength={field === 'password' && mode === 'register' ? 8 : undefined}
              onInvalid={({ currentTarget: input }) => {
                const { valueMissing, typeMismatch, tooShort } = input.validity
                if (valueMissing) input.setCustomValidity('Užpildykite šį lauką.')
                else if (typeMismatch) input.setCustomValidity('Įveskite galiojantį el. pašto adresą.')
                else if (tooShort) input.setCustomValidity(`Įveskite bent ${input.minLength} simbolių.`)
              }}
              onInput={({ currentTarget }) => currentTarget.setCustomValidity('')}
              aria-invalid={Boolean(errors[field])} aria-describedby={errors[field] ? `${field}-error` : undefined} disabled={busy} />
            {errors[field] && <p id={`${field}-error`} className={styles.error}>{errors[field].join(' ')}</p>}
          </div>)}
          {message && <p role="alert" className={styles.error}>{message}</p>}
          <button className={styles.primary} type="submit" disabled={busy || loading}>{busy ? 'Palaukite…' : title}</button>
        </form>
        <Link to={`/${mode === 'register' ? 'login' : 'register'}${raw ? `?redirect=${encodeURIComponent(destination)}` : ''}`}>{mode === 'register' ? 'Jau turite paskyrą? Prisijunkite' : 'Sukurti paskyrą'}</Link>
        {destination === '/checkout' && <p className={styles.cancel}><Link to="/cart">Atšaukti ir grįžti į krepšelį</Link></p>}
      </section>
    </main>
  )
}
