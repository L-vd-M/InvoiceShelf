import { client } from '../client'
import type { ApiResponse, PaginatedResponse } from '@/scripts/types/api'
import type {
  Revision,
  RevisionDiff,
  RevisionDocumentType,
} from '@/scripts/types/domain/revision'

const COLLECTION: Record<RevisionDocumentType, string> = {
  invoice: 'invoices',
  estimate: 'estimates',
  credit_note: 'credit-notes',
}

export const revisionService = {
  async list(
    type: RevisionDocumentType,
    documentId: number,
    params?: { page?: number; limit?: number },
  ): Promise<PaginatedResponse<Revision>> {
    const { data } = await client.get(
      `/api/v1/${COLLECTION[type]}/${documentId}/revisions`,
      { params },
    )
    return data
  },

  async diff(revisionId: number, against: number | 'previous' = 'previous'): Promise<ApiResponse<RevisionDiff>> {
    const { data } = await client.get(`/api/v1/revisions/${revisionId}/diff`, {
      params: { against },
    })
    return data
  },

  async pdf(revisionId: number): Promise<Blob> {
    const { data } = await client.get(`/api/v1/revisions/${revisionId}/pdf`, {
      responseType: 'blob',
    })
    return data
  },
}
