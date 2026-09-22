import { client } from '../client'
import { API } from '../endpoints'
import type { ApiResponse, ListParams } from '@/scripts/types/api'

export interface CustomerCompanyAddress {
  address_street_1?: string | null
  address_street_2?: string | null
  city?: string | null
  state?: string | null
  country_id?: number | null
  zip?: string | null
  phone?: string | null
}

export interface CustomerCompany {
  id: number
  name: string
  tax_id: string | null
  vat_id: string | null
  phone: string | null
  website: string | null
  customers_count?: number
  address?: CustomerCompanyAddress | null
}

export interface CustomerCompanyPayload {
  name: string
  tax_id?: string | null
  vat_id?: string | null
  phone?: string | null
  website?: string | null
  address?: CustomerCompanyAddress
}

export const customerCompanyService = {
  async list(params?: ListParams): Promise<ApiResponse<CustomerCompany[]>> {
    const { data } = await client.get(API.CUSTOMER_COMPANIES, { params })
    return data
  },

  async get(id: number): Promise<ApiResponse<CustomerCompany>> {
    const { data } = await client.get(`${API.CUSTOMER_COMPANIES}/${id}`)
    return data
  },

  async create(payload: CustomerCompanyPayload): Promise<ApiResponse<CustomerCompany>> {
    const { data } = await client.post(API.CUSTOMER_COMPANIES, payload)
    return data
  },

  async update(id: number, payload: Partial<CustomerCompanyPayload>): Promise<ApiResponse<CustomerCompany>> {
    const { data } = await client.put(`${API.CUSTOMER_COMPANIES}/${id}`, payload)
    return data
  },

  async delete(id: number): Promise<{ success: boolean }> {
    const { data } = await client.delete(`${API.CUSTOMER_COMPANIES}/${id}`)
    return data
  },

  async syncCustomer(customerId: number): Promise<ApiResponse<unknown>> {
    const { data } = await client.post(`${API.CUSTOMER_SYNC_COMPANY}/${customerId}/sync-company`)
    return data
  },
}
