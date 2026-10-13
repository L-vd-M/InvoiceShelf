<template>
  <BasePage>
    <BasePageHeader :title="$t('credit_notes.title')">
      <BaseBreadcrumb>
        <BaseBreadcrumbItem :title="$t('general.home')" to="dashboard" />
        <BaseBreadcrumbItem :title="$t('credit_notes.title')" to="#" active />
      </BaseBreadcrumb>
    </BasePageHeader>

    <div class="mt-6 bg-surface border border-line-default rounded-md overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-muted border-b border-line-default">
            <th class="p-3">{{ $t('credit_notes.number') }}</th>
            <th class="p-3">{{ $t('credit_notes.date') }}</th>
            <th class="p-3">{{ $t('credit_notes.against_invoice') }}</th>
            <th class="p-3">{{ $t('credit_notes.customer') }}</th>
            <th class="p-3">{{ $t('credit_notes.reason') }}</th>
            <th class="p-3 text-right">{{ $t('credit_notes.total') }}</th>
            <th class="p-3 text-right">{{ $t('credit_notes.unapplied') }}</th>
            <th class="p-3" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="!rows.length && !isLoading">
            <td colspan="8" class="p-6 text-center text-muted">{{ $t('credit_notes.none') }}</td>
          </tr>
          <tr v-for="row in rows" :key="row.id" class="border-b border-line-default">
            <td class="p-3 font-medium text-heading">{{ row.credit_note_number }}</td>
            <td class="p-3">{{ row.credit_note_date }}</td>
            <td class="p-3">
              <router-link
                :to="`/admin/invoices/${row.invoice_id}/view`"
                class="text-primary-500 hover:underline"
              >
                {{ row.invoice_number }}
              </router-link>
            </td>
            <td class="p-3">{{ row.customer?.name }}</td>
            <td class="p-3">{{ row.reason }}</td>
            <td class="p-3 text-right">
              <BaseFormatMoney :amount="row.total" :currency="row.customer?.currency" />
            </td>
            <td class="p-3 text-right">
              <BaseFormatMoney
                v-if="row.unapplied_amount > 0"
                :amount="row.unapplied_amount"
                :currency="row.customer?.currency"
              />
              <span v-else>-</span>
            </td>
            <td class="p-3 text-right">
              <button class="text-primary-500 hover:underline" type="button" @click="openPdf(row.id)">
                PDF
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="lastPage > 1" class="flex justify-end mt-4 gap-3">
      <BaseButton variant="primary-outline" :disabled="page <= 1" @click="go(page - 1)">
        {{ $t('credit_notes.previous') }}
      </BaseButton>
      <span class="self-center text-sm text-muted">{{ page }} / {{ lastPage }}</span>
      <BaseButton variant="primary-outline" :disabled="page >= lastPage" @click="go(page + 1)">
        {{ $t('credit_notes.next') }}
      </BaseButton>
    </div>
  </BasePage>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { creditNoteService } from '@/scripts/api/services/credit-note.service'
import type { CreditNote } from '@/scripts/types/domain/credit-note'

const rows = ref<CreditNote[]>([])
const page = ref(1)
const lastPage = ref(1)
const isLoading = ref(false)

async function load(): Promise<void> {
  isLoading.value = true
  try {
    const res = await creditNoteService.list({ page: page.value, limit: 15 })
    rows.value = res.data
    lastPage.value = res.meta?.last_page ?? 1
  } finally {
    isLoading.value = false
  }
}

function go(p: number): void {
  page.value = p
  load()
}

function openPdf(id: number): void {
  creditNoteService.openPdf(id)
}

onMounted(load)
</script>
