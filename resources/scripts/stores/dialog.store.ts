import { defineStore } from 'pinia'
import { ref } from 'vue'

export type DialogVariant = 'primary' | 'danger'

export type DialogSize = 'sm' | 'md' | 'lg'

export interface DeletionReasonPayload {
  reason_code: string
  reason?: string
}

export const DELETION_REASON_CODES = [
  'created_in_error',
  'duplicate',
  'wrong_customer',
  'wrong_amounts',
  'customer_cancelled',
  'superseded',
  'other',
] as const

export interface OpenDialogPayload {
  title: string
  message: string
  size?: DialogSize
  data?: unknown
  variant?: DialogVariant
  yesLabel?: string
  noLabel?: string
  hideNoButton?: boolean
  reasonRequired?: boolean
  reasonLabel?: string
}

export const useDialogStore = defineStore('dialog', () => {
  // State
  const active = ref<boolean>(false)
  const title = ref<string>('')
  const message = ref<string>('')
  const size = ref<DialogSize>('md')
  const data = ref<unknown>(null)
  const variant = ref<DialogVariant>('danger')
  const yesLabel = ref<string>('Yes')
  const noLabel = ref<string>('No')
  const hideNoButton = ref<boolean>(false)
  const reasonRequired = ref<boolean>(false)
  const reasonLabel = ref<string>('')
  // chosen option and typed text; both stay readable after the dialog closes so the caller can send them
  const reasonCode = ref<string>('')
  const reason = ref<string>('')
  const resolve = ref<((value: boolean) => void) | null>(null)

  // Actions
  function openDialog(payload: OpenDialogPayload): Promise<boolean> {
    active.value = true
    title.value = payload.title
    message.value = payload.message
    size.value = payload.size ?? 'md'
    data.value = payload.data ?? null
    variant.value = payload.variant ?? 'danger'
    yesLabel.value = payload.yesLabel ?? 'Yes'
    noLabel.value = payload.noLabel ?? 'No'
    hideNoButton.value = payload.hideNoButton ?? false
    reasonRequired.value = payload.reasonRequired ?? false
    reasonLabel.value = payload.reasonLabel ?? ''
    reasonCode.value = ''
    reason.value = ''

    return new Promise<boolean>((res) => {
      resolve.value = res
    })
  }

  function confirm(): void {
    if (resolve.value) {
      resolve.value(true)
    }
    closeDialog()
  }

  function cancel(): void {
    if (resolve.value) {
      resolve.value(false)
    }
    closeDialog()
  }

  function closeDialog(): void {
    active.value = false

    setTimeout(() => {
      title.value = ''
      message.value = ''
      data.value = null
      resolve.value = null
    }, 300)
  }

  return {
    active,
    title,
    message,
    size,
    data,
    variant,
    yesLabel,
    noLabel,
    hideNoButton,
    reasonRequired,
    reasonLabel,
    reasonCode,
    reason,
    resolve,
    openDialog,
    confirm,
    cancel,
    closeDialog,
  }
})
