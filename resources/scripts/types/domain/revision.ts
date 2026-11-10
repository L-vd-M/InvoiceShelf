export type RevisionDocumentType = 'invoice' | 'estimate' | 'credit_note'

export type RevisionChangeKind =
  | 'header_changed'
  | 'line_added'
  | 'line_removed'
  | 'quantity_changed'
  | 'unit_price_changed'
  | 'discount_changed'
  | 'description_changed'
  | 'tax_changed'
  | 'line_changed'

export interface RevisionDiffSummary {
  changes: number
  kinds: Partial<Record<RevisionChangeKind, number>>
}

export interface Revision {
  id: number
  document_type: RevisionDocumentType
  document_id: number
  revision_no: number
  reason_code: string
  note: string | null
  status: string
  totals_hash: string
  pdf_sha256: string | null
  has_pdf: boolean
  actor_user_id: number | null
  actor_name: string | null
  actor_role: string | null
  source: string
  created_at: string
  diff_summary: RevisionDiffSummary
}

export interface RevisionChange {
  kind: RevisionChangeKind
  path: string
  line?: string | null
  before: unknown
  after: unknown
}

export interface RevisionDiff {
  revision_id: number
  against_id: number | null
  changes: RevisionChange[]
  summary: RevisionDiffSummary
}
