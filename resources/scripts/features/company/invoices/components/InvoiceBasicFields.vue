<template>
  <div class="grid grid-cols-12 gap-8 mt-6 mb-8">
    <div class="col-span-12 lg:col-span-6 pr-0">
      <BaseCustomerSelectPopup
        :valid="v.customer_id"
        :content-loading="isLoading"
        type="invoice"
      />

      <div
        v-if="hasStaleCompanyInfo"
        class="mt-2 p-3 rounded-lg bg-orange-50 text-orange-700 text-sm flex items-center justify-between"
      >
        <span>This customer's linked company info has changed since it was last synced.</span>
        <BaseButton size="sm" variant="primary-outline" :loading="isSyncing" @click="syncCustomerCompany">
          Update now
        </BaseButton>
      </div>
    </div>

    <RecurringFields
      v-if="isRecurring"
      :is-loading="isLoading"
      :is-edit="isEdit"
    />

    <BaseInputGrid
      v-else
      class="col-span-12 lg:col-span-6 rounded-xl shadow border border-line-light bg-surface p-5"
    >
      <BaseInputGroup
        :label="$t('invoices.invoice_date')"
        :content-loading="isLoading"
        required
        :error="v.invoice_date.$error && v.invoice_date.$errors[0].$message"
      >
        <BaseDatePicker
          v-model="invoiceStore.newInvoice.invoice_date"
          :content-loading="isLoading"
          :calendar-button="true"
          calendar-button-icon="calendar"
          :enable-time="enableTime"
          :time24hr="time24h"
        />
      </BaseInputGroup>

      <BaseInputGroup
        :label="$t('invoices.due_date')"
        :content-loading="isLoading"
      >
        <BaseDatePicker
          v-if="showDueDate"
          v-model="invoiceStore.newInvoice.due_date"
          :content-loading="isLoading"
          :calendar-button="true"
          calendar-button-icon="calendar"
        />
        <BaseCheckbox
          v-model="showDueDate"
          class="mt-2"
          :label="$t('invoices.show_due_date')"
        />
      </BaseInputGroup>

      <BaseInputGroup :content-loading="isLoading">
        <BaseCheckbox
          v-model="invoiceStore.newInvoice.tax_included"
          :label="$t('settings.tax_types.tax_included')"
          :description="$t('settings.tax_types.tax_included_description')"
        />
      </BaseInputGroup>

      <BaseInputGroup
        :label="$t('invoices.invoice_number')"
        :content-loading="isLoading"
        :error="v.invoice_number.$error && v.invoice_number.$errors[0].$message"
        required
      >
        <BaseInput
          v-model="invoiceStore.newInvoice.invoice_number"
          :content-loading="isLoading"
          @input="v.invoice_number.$touch()"
        />
      </BaseInputGroup>

      <ExchangeRateConverter
        :store="invoiceStore"
        store-prop="newInvoice"
        :v="v"
        :is-loading="isLoading"
        :is-edit="isEdit"
        :customer-currency="invoiceStore.newInvoice.currency_id"
      />
    </BaseInputGrid>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ExchangeRateConverter } from '../../../shared/document-form'
import { useInvoiceStore } from '../store'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { customerCompanyService } from '@/scripts/api/services/customer-company.service'
import RecurringFields from './RecurringFields.vue'

interface ValidationField {
  $error: boolean
  $errors: Array<{ $message: string }>
  $touch: () => void
}

interface Props {
  v: Record<string, ValidationField>
  isLoading?: boolean
  isEdit?: boolean
  isRecurring?: boolean
  companySettings?: Record<string, string>
}

const props = withDefaults(defineProps<Props>(), {
  isLoading: false,
  isEdit: false,
  isRecurring: false,
  companySettings: () => ({}),
})

const invoiceStore = useInvoiceStore()
const notificationStore = useNotificationStore()

const isSyncing = ref<boolean>(false)

// The invoice PDF already reads live from customer->billingAddress at render
// time (not a frozen per-invoice snapshot), so "update this invoice" really
// just means "update the customer" -- no separate invoice-side sync needed.
const hasStaleCompanyInfo = computed<boolean>(() => {
  const customer = invoiceStore.newInvoice.customer as
    | { has_stale_company_info?: boolean }
    | null
  return !!customer?.has_stale_company_info
})

async function syncCustomerCompany(): Promise<void> {
  const customer = invoiceStore.newInvoice.customer as { id?: number } | null
  if (!customer?.id) return

  isSyncing.value = true
  try {
    const response = await customerCompanyService.syncCustomer(customer.id)
    invoiceStore.newInvoice.customer = response.data as typeof invoiceStore.newInvoice.customer
    notificationStore.showNotification({
      type: 'success',
      message: 'general.updated_successfully',
    })
  } finally {
    isSyncing.value = false
  }
}

// due_date is nullable on the backend; unchecking this clears it so the PDF
// omits the Due Date row entirely, rather than just hiding the field here.
// Two-way watch (not a one-shot ref) because edit mode loads the invoice
// asynchronously after this component mounts -- a one-shot read here would
// freeze showDueDate on whatever due_date happened to be at setup time and
// never reflect the real value once the fetch resolves.
const showDueDate = ref<boolean>(!!invoiceStore.newInvoice.due_date)
let lastDueDate: string | null = invoiceStore.newInvoice.due_date ?? null

watch(
  () => invoiceStore.newInvoice.due_date,
  (value) => {
    if (value) {
      lastDueDate = value
    }
    showDueDate.value = !!value
  }
)

watch(showDueDate, (visible) => {
  if (visible) {
    if (!invoiceStore.newInvoice.due_date) {
      invoiceStore.newInvoice.due_date = lastDueDate
    }
  } else {
    invoiceStore.newInvoice.due_date = null
  }
})

const enableTime = computed<boolean>(() => {
  return props.companySettings?.invoice_use_time === 'YES'
})

const time24h = computed<boolean>(() => {
  const format = props.companySettings?.carbon_time_format ?? ''
  return format.indexOf('H') > -1
})
</script>
