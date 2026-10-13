import type { Customer } from './customer'

export interface CreditNoteItem {
  id: number
  invoice_item_id: number | null
  name: string
  description: string | null
  quantity: number
  price: number
  discount_val: number
  tax: number
  total: number
}

export interface CreditNote {
  id: number
  credit_note_number: string
  credit_note_date: string
  status: string
  invoice_id: number
  invoice_number?: string
  customer_id: number
  customer?: Customer
  reason_code: string
  reason: string | null
  sub_total: number
  discount_val: number
  tax: number
  total: number
  applied_amount: number
  unapplied_amount: number
  currency_id: number | null
  exchange_rate: number | null
  sequence_number: number | null
  unique_hash: string | null
  items?: CreditNoteItem[]
  created_at: string
}

export interface CreditableItem {
  invoice_item_id: number
  name: string
  description: string | null
  quantity: number
  price: number
  total: number
  credited_quantity: number
  remaining_quantity: number
}

export interface Creditable {
  invoice_id: number
  invoice_number: string
  total: number
  credited_total: number
  remaining: number
  due_amount: number
  can_credit: boolean
  items: CreditableItem[]
}

export interface CreateCreditNotePayload {
  reason_code: string
  reason?: string | null
  credit_note_date?: string | null
  items?: Array<{ invoice_item_id: number; quantity: number }>
}

export const CREDIT_NOTE_REASON_CODES = [
  'goods_returned',
  'price_correction',
  'billing_error',
  'service_not_delivered',
  'goodwill',
  'other',
] as const
