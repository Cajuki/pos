import axios from 'axios'

export interface ApiHealth {
  status: 'ok'
  service: string
  version: string
}

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1',
  timeout: 10_000,
  headers: { Accept: 'application/json' },
})

apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('pos_token')
  const businessId = localStorage.getItem('pos_business_id')

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  if (businessId) {
    config.headers['X-Business-ID'] = businessId
  }

  return config
})

export async function getApiHealth(): Promise<ApiHealth> {
  const response = await apiClient.get<ApiHealth>('/health')
  return response.data
}