<template>
  <BaseModal :show="show" @close="emit('close')">
    <template #header>
      <div class="flex justify-between w-full">
        {{ $t('estimates.confirm_payment_issue_invoice') }}
        <BaseIcon
          name="XMarkIcon"
          class="h-6 w-6 text-muted cursor-pointer"
          @click="emit('close')"
        />
      </div>
    </template>

    <form @submit.prevent="submit">
      <div class="px-8 py-8 sm:p-6">
        <p class="mb-4 text-sm text-body">
          {{ $t('estimates.issue_invoice_description') }}
        </p>

        <BaseInputGrid layout="one-column">
          <BaseInputGroup :label="$t('estimates.payment_amount')">
            <BaseFormatMoney
              :amount="estimate.total"
              :currency="estimate.customer?.currency"
              class="text-lg font-semibold text-heading"
            />
          </BaseInputGroup>

          <BaseInputGroup :label="$t('payments.date')" required>
            <BaseDatePicker
              v-model="form.payment_date"
              :calendar-button="true"
              calendar-button-icon="calendar"
            />
          </BaseInputGroup>

          <BaseInputGroup :label="$t('estimates.payment_notes')">
            <BaseTextarea v-model="form.notes" rows="2" />
          </BaseInputGroup>

          <div class="flex items-center">
            <BaseSwitch v-model="form.send_email" class="mr-2" />
            <span class="text-sm font-medium text-heading">
              {{ $t('estimates.email_invoice_to_customer') }}
            </span>
          </div>
        </BaseInputGrid>
      </div>

      <div class="z-0 flex justify-end px-4 py-4 border-t border-line-default border-solid">
        <BaseButton variant="primary-outline" type="button" class="mr-3" @click="emit('close')">
          {{ $t('general.cancel') }}
        </BaseButton>
        <BaseButton :loading="isSaving" :disabled="isSaving" variant="primary" type="submit">
          {{ $t('estimates.issue_invoice') }}
        </BaseButton>
      </div>
    </form>
  </BaseModal>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useEstimateStore } from '../store'
import { useNotificationStore } from '../../../../stores/notification.store'
import type { Estimate } from '../../../../types/domain/estimate'

interface Props {
  show: boolean
  estimate: Estimate
}

const props = defineProps<Props>()
const emit = defineEmits<{ (e: 'close'): void }>()

const estimateStore = useEstimateStore()
const notificationStore = useNotificationStore()
const router = useRouter()
const { t } = useI18n()

const isSaving = ref(false)
const form = reactive({
  payment_date: new Date().toISOString().slice(0, 10),
  notes: '',
  send_email: true,
})

async function submit(): Promise<void> {
  isSaving.value = true
  try {
    const response = await estimateStore.issueInvoice(props.estimate.id, {
      payment_date: form.payment_date,
      amount: props.estimate.total,
      notes: form.notes || null,
      send_email: form.send_email,
    })
    notificationStore.showNotification({ type: 'success', message: t('estimates.invoice_issued') })
    emit('close')
    router.push(`/admin/invoices/${response.data.data.id}/view`)
  } finally {
    isSaving.value = false
  }
}
</script>
