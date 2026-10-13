import { client } from '../client'
import { API } from '../endpoints'
import type {
  CreditNote,
  Creditable,
  CreateCreditNotePayload,
} from '@/scripts/types/domain/credit-note'
import type { ApiResponse } from '@/scripts/types/api'

export interface CreditNoteListParams {
  invoice_id?: number
  customer_id?: number
  from_date?: string
  to_date?: string
  page?: number
  limit?: number
}

export interface CreditNoteListResponse {
  data: CreditNote[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export const creditNoteService = {
  async list(params?: CreditNoteListParams): Promise<CreditNoteListResponse> {
    const { data } = await client.get(API.CREDIT_NOTES, { params })
    return data
  },

  async get(id: number): Promise<ApiResponse<CreditNote>> {
    const { data } = await client.get(`${API.CREDIT_NOTES}/${id}`)
    return data
  },

  async creditable(invoiceId: number): Promise<{ data: Creditable }> {
    const { data } = await client.get(`/api/v1/invoices/${invoiceId}/creditable`)
    return data
  },

  async create(
    invoiceId: number,
    payload: CreateCreditNotePayload,
  ): Promise<ApiResponse<CreditNote>> {
    const { data } = await client.post(`/api/v1/invoices/${invoiceId}/credit-notes`, payload)
    return data
  },

  pdfUrl(id: number): string {
    return `${API.CREDIT_NOTES}/${id}/pdf`
  },

  async openPdf(id: number): Promise<void> {
    const { data } = await client.get(`${API.CREDIT_NOTES}/${id}/pdf`, { responseType: 'blob' })
    window.open(URL.createObjectURL(data), '_blank')
  },
}
