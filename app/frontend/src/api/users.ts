import { api } from './client'

export interface User {
  id: number
  username: string
  email: string
  roles: string[]
  createdAt: string
}

export interface UserPayload {
  username: string
  email: string
  password?: string
  roles: string[]
}

export function getUsers(): Promise<User[]> {
  return api.get<User[]>('/api/users')
}

export function createUser(payload: UserPayload): Promise<User> {
  return api.post<User>('/api/users', payload)
}

export function updateUser(id: number, payload: UserPayload): Promise<User> {
  return api.put<User>(`/api/users/${id}`, payload)
}

export function deleteUser(id: number): Promise<void> {
  return api.delete<void>(`/api/users/${id}`)
}

export function changeMyPassword(currentPassword: string, newPassword: string): Promise<void> {
  return api.put<void>('/api/users/me/password', { currentPassword, newPassword })
}
