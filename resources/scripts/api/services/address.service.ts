import { client } from '../client'
import { API } from '../endpoints'

export interface AddressSuggestion {
  label: string
  address_street_1: string
  city: string
  state: string
  zip: string
  country_code: string
  lat: number
  lng: number
}

export interface AddressSuggestResponse {
  suggestions: AddressSuggestion[]
  error?: 'unavailable'
}

export const addressService = {
  async suggest(
    q: string,
    cc?: string,
    signal?: AbortSignal
  ): Promise<AddressSuggestResponse> {
    const { data } = await client.get<AddressSuggestResponse>(
      API.ADDRESS_SUGGEST,
      { params: { q, cc: cc || undefined }, signal }
    )
    return data
  },
}
