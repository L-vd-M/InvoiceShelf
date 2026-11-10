export type DealOutcome = 'open' | 'won' | 'lost' | 'expired'

export interface DealOwner {
  id: number
  name: string
  sales_team: string | null
}

export interface DealLifecycle {
  quote_id: number
  customer_id: number | null
  invoice_id: number | null
  outcome: DealOutcome
  lost_reason: string | null
  owner: DealOwner | null
  created_at: string | null
  first_sent_at: string | null
  accepted_at: string | null
  invoiced_at: string | null
  first_paid_at: string | null
  fully_paid_at: string | null
  revision_count: number
  price_change_count: number
  first_total_minor: number | null
  final_total_minor: number | null
  discount_pct_first_to_final: number | null
  currency: string | null
  built_at: string | null
}

export interface MarkLostPayload {
  reason_code: string
  note?: string | null
}
