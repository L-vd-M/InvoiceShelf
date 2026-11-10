import { client } from '../client'
import type { ApiResponse } from '@/scripts/types/api'
import type { DealLifecycle, DealOwner, MarkLostPayload } from '@/scripts/types/domain/deal'

export const dealService = {
  async lifecycle(estimateId: number): Promise<ApiResponse<DealLifecycle>> {
    const { data } = await client.get(`/api/v1/estimates/${estimateId}/lifecycle`)
    return data
  },

  async markLost(estimateId: number, payload: MarkLostPayload): Promise<ApiResponse<DealLifecycle>> {
    const { data } = await client.post(`/api/v1/estimates/${estimateId}/lost`, payload)
    return data
  },

  async assign(estimateId: number, userId: number): Promise<ApiResponse<DealLifecycle>> {
    const { data } = await client.post(`/api/v1/estimates/${estimateId}/assign`, { user_id: userId })
    return data
  },

  async lostReasons(): Promise<ApiResponse<string[]>> {
    const { data } = await client.get('/api/v1/deal-lost-reasons')
    return data
  },

  async owners(): Promise<ApiResponse<DealOwner[]>> {
    const { data } = await client.get('/api/v1/deal-owners')
    return data
  },
}
