import { getApiBaseUrl, getResponse } from './games'

export type User = { id: number; first_name: string; last_name: string; email: string; status: 'active' | 'blocked' }
export type Credentials = { email: string; password: string }
export type Registration = Credentials & { first_name: string; last_name: string }
export type AuthResult = { data: User; token: string }
export class AuthError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(message: string, status: number, errors: Record<string, string[]> = {}) {
    super(message); this.status = status; this.errors = errors
  }
}
function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function isUser(value: unknown): value is User {
  if (!value || typeof value !== 'object') return false
  const u = value as Partial<User>
  return Number.isSafeInteger(u.id) && Number(u.id) > 0 && typeof u.first_name === 'string'
    && typeof u.last_name === 'string' && typeof u.email === 'string' && (u.status === 'active' || u.status === 'blocked')
}
async function request(path: string, method: string, signal: AbortSignal, token?: string, body?: unknown) {
  if (import.meta.env.PROD && new URL(getApiBaseUrl(), window.location.origin).protocol !== 'https:') {
    throw new Error('Authentication requires HTTPS in production')
  }
  const response = await getResponse(path, signal, { method,
    headers: { ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    ...(body ? { body: JSON.stringify(body) } : {}),
  })
  const payload: unknown = await response.json()
  if (!isRecord(payload)) throw new Error('Invalid authentication response')
  if (!response.ok) {
    const errors: Record<string, string[]> = {}
    if (isRecord(payload.errors)) for (const [field, messages] of Object.entries(payload.errors)) {
      if (Array.isArray(messages) && messages.every((message) => typeof message === 'string')) errors[field] = messages
    }
    throw new AuthError(typeof payload.message === 'string' ? payload.message : 'Authentication request failed.', response.status, errors)
  }
  return payload
}
export async function authenticate(mode: 'login' | 'register', data: Credentials | Registration, signal: AbortSignal): Promise<AuthResult> {
  const result = await request(`/${mode}`, 'POST', signal, undefined, data)
  if (!isUser(result.data) || result.data.status !== 'active' || typeof result.token !== 'string' || !result.token) throw new Error('Invalid authentication response')
  return { data: result.data, token: result.token }
}
export async function getMe(token: string, signal: AbortSignal): Promise<User> {
  const result = await request('/me', 'GET', signal, token)
  if (!isUser(result.data) || result.data.status !== 'active') throw new Error('Invalid user response')
  return result.data
}
export async function logout(token: string, signal: AbortSignal): Promise<void> {
  await request('/logout', 'POST', signal, token)
}
