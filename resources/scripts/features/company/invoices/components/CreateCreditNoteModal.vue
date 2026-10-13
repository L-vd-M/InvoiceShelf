<template>
  <BaseModal :show="modalActive" @close="closeModal" @open="loadCreditable">
    <template #header>
      <div class="flex justify-between w-full">
        {{ $t('credit_notes.create_title') }}
        <BaseIcon
          name="XMarkIcon"
          class="w-6 h-6 text-muted cursor-pointer"
          @click="closeModal"
        />
      </div>
    </template>

    <div class="px-8 py-6 sm:p-6">
      <p v-if="isLoading" class="text-sm text-muted">{{ $t('credit_notes.loading') }}</p>

      <p v-else-if="creditable && !creditable.can_credit" class="text-sm text-body">
        {{ $t('credit_notes.nothing_to_credit') }}
      </p>

      <form v-else-if="creditable" @submit.prevent="submit">
        <p class="mb-4 text-sm text-muted">
          {{ $t('credit_notes.against') }} {{ creditable.invoice_number }}.
          {{ $t('credit_notes.remaining') }}:
          <BaseFormatMoney :amount="creditable.remaining" :currency="currency" />
        </p>

        <label for="cn-reason" class="block text-sm font-medium text-heading">
          {{ $t('credit_notes.reason') }}
        </label>
        <select
          id="cn-reason"
          v-model="reasonCode"
          class="mt-1 block w-full rounded-md border border-line-default bg-surface p-2 text-sm text-heading"
        >
          <option value="" disabled>{{ $t('general.deletion_reason_choose') }}</option>
          <option v-for="code in CREDIT_NOTE_REASON_CODES" :key="code" :value="code">
            {{ $t(`credit_notes.reasons.${code}`) }}
          </option>
        </select>
        <textarea
          v-if="reasonCode === 'other'"
          v-model="reasonText"
          rows="3"
          maxlength="500"
          :placeholder="$t('general.deletion_reason_other_hint')"
          class="mt-2 block w-full rounded-md border border-line-default bg-surface p-2 text-sm text-heading"
        />

        <table class="w-full mt-6 text-sm">
          <thead>
            <tr class="text-left text-muted">
              <th class="py-2">{{ $t('credit_notes.line') }}</th>
              <th class="py-2 text-right">{{ $t('credit_notes.left') }}</th>
              <th class="py-2 text-right w-28">{{ $t('credit_notes.credit_qty') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in creditable.items"
              :key="item.invoice_item_id"
              class="border-t border-line-default"
            >
              <td class="py-2">{{ item.name }}</td>
              <td class="py-2 text-right">{{ item.remaining_quantity }}</td>
              <td class="py-2 text-right">
                <input
                  v-model.number="quantities[item.invoice_item_id]"
                  type="number"
                  min="0"
                  :max="item.remaining_quantity"
                  step="any"
                  :disabled="item.remaining_quantity <= 0"
                  class="w-24 rounded-md border border-line-default bg-surface p-1 text-right text-sm text-heading"
                />
              </td>
            </tr>
          </tbody>
        </table>

        <p class="mt-4 text-sm text-heading">
          {{ $t('credit_notes.lines_total') }}:
          <BaseFormatMoney :amount="linesTotal" :currency="currency" />
        </p>
        <p v-if="errorMessage" class="mt-2 text-sm text-red-600">{{ errorMessage }}</p>
      </form>
    </div>

    <div class="z-0 flex justify-end p-4 border-t border-line-default border-solid">
      <BaseButton class="mr-3" variant="primary-outline" type="button" @click="closeModal">
        {{ $t('general.cancel') }}
      </BaseButton>
      <BaseButton
        v-if="creditable?.can_credit"
        variant="primary"
        type="button"
        :loading="isSaving"
        :disabled="!canSubmit || isSaving"
        @click="submit"
      >
        {{ $t('credit_notes.issue') }}
      </BaseButton>
    </div>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { creditNoteService } from '@/scripts/api/services/credit-note.service'
import { useModalStore } from '../../../../stores/modal.store'
import { useNotificationStore } from '../../../../stores/notification.store'
import { CREDIT_NOTE_REASON_CODES } from '../../../../types/domain/credit-note'
import type { Creditable } from '../../../../types/domain/credit-note'
import type { Invoice } from '../../../../types/domain/invoice'

const modalStore = useModalStore()
const notificationStore = useNotificationStore()
const router = useRouter()

const isLoading = ref(false)
const isSaving = ref(false)
const errorMessage = ref('')
const creditable = ref<Creditable | null>(null)
const reasonCode = ref('')
const reasonText = ref('')
const quantities = reactive<Record<number, number>>({})

const modalActive = computed<boolean>(
  () => modalStore.active && modalStore.componentName === 'CreateCreditNoteModal',
)

const invoice = computed(() => modalStore.data as Invoice | null)
const currency = computed(() => invoice.value?.currency ?? invoice.value?.customer?.currency ?? null)

const linesTotal = computed<number>(() => {
  if (!creditable.value) return 0
  return creditable.value.items.reduce((sum, item) => {
    const q = Number(quantities[item.invoice_item_id] || 0)
    return sum + (item.quantity > 0 ? Math.round(item.total * (q / item.quantity)) : 0)
  }, 0)
})

const canSubmit = computed<boolean>(() => {
  if (!reasonCode.value || linesTotal.value <= 0) return false
  return reasonCode.value !== 'other' || reasonText.value.trim().length >= 3
})

async function loadCreditable(): Promise<void> {
  errorMessage.value = ''
  reasonCode.value = ''
  reasonText.value = ''
  creditable.value = null
  isLoading.value = true
  try {
    const { data } = await creditNoteService.creditable(Number(modalStore.id))
    creditable.value = data
    for (const item of data.items) {
      quantities[item.invoice_item_id] = item.remaining_quantity
    }
  } finally {
    isLoading.value = false
  }
}

async function submit(): Promise<void> {
  if (!creditable.value || !canSubmit.value) return
  isSaving.value = true
  errorMessage.value = ''
  try {
    const items = creditable.value.items
      .map((i) => ({ invoice_item_id: i.invoice_item_id, quantity: Number(quantities[i.invoice_item_id] || 0) }))
      .filter((i) => i.quantity > 0)
    const { data } = await creditNoteService.create(creditable.value.invoice_id, {
      reason_code: reasonCode.value,
      reason: reasonCode.value === 'other' ? reasonText.value.trim() : null,
      items,
    })
    notificationStore.showNotification({
      type: 'success',
      message: `${data.credit_note_number}`,
    })
    if (modalStore.refreshData) {
      modalStore.refreshData()
    }
    closeModal()
    router.push('/admin/credit-notes')
  } catch (e: unknown) {
    const err = e as { response?: { data?: { message?: string } } }
    errorMessage.value = err.response?.data?.message ?? 'Could not create the credit note'
  } finally {
    isSaving.value = false
  }
}

function closeModal(): void {
  modalStore.closeModal()
}
</script>
