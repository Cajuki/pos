import { apiClient } from './client'

export interface BusinessMembership {
  id: string
  name: string
  currency: string
  timezone: string
  role: string
}

export interface AuthUser {
  id: number
  name: string
  email: string
}

export interface AuthPayload {
  token: string
  user: AuthUser
  businesses: BusinessMembership[]
}

export function saveAuthSession(session: {
  token: string
  user: AuthUser
  business?: Pick<BusinessMembership, 'id' | 'name'> & { role?: string }
}): void {
  localStorage.setItem('pos_token', session.token)
  localStorage.setItem('pos_user', JSON.stringify(session.user))

  if (session.business) {
    localStorage.setItem('pos_business_id', session.business.id)
    localStorage.setItem('pos_business_name', session.business.name)
    localStorage.setItem('pos_user_role', session.business.role ?? 'member')
    return
  }

  localStorage.removeItem('pos_business_id')
  localStorage.removeItem('pos_business_name')
  localStorage.removeItem('pos_user_role')
}

export interface LoginRequest {
  email: string
  password: string
  device_name?: string
}

export async function login(credentials: LoginRequest): Promise<AuthPayload> {
  const response = await apiClient.post<{ data: AuthPayload }>('/auth/login', credentials)
  return response.data.data
}

export async function registerBusiness(payload: {
  business_name: string
  name: string
  email: string
  password: string
  password_confirmation: string
}) {
  const response = await apiClient.post<{ data: {
    token: string
    user: AuthUser
    business: Pick<BusinessMembership, 'id' | 'name' | 'currency' | 'timezone'>
    role: string
  } }>('/auth/register-business', payload)

  return response.data.data
}

export async function logout(token?: string) {
  await apiClient.post('/auth/logout', undefined, token
    ? { headers: { Authorization: `Bearer ${token}` } }
    : undefined)
}

export async function getCurrentUser() {
  const response = await apiClient.get<{ data: AuthUser }>('/user')
  return response.data.data
}

export async function getCurrentBusiness() {
  const response = await apiClient.get<{ data: BusinessMembership }>('/business/current', {
    headers: {
      'X-Business-ID': localStorage.getItem('pos_business_id') ?? '',
    },
  })

  return response.data.data
}
