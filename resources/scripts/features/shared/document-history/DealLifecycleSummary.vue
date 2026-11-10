<template>
  <div v-if="deal" class="mt-6 p-4 border border-line-default rounded-md">
    <h4 class="mb-3 text-sm font-semibold text-heading">{{ $t('estimates.deal.title') }}</h4>

    <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
      <div>
        <dt class="text-muted">{{ $t('estimates.deal.outcome') }}</dt>
        <dd class="font-medium" :class="outcomeClass">{{ $t(`estimates.deal.outcomes.${deal.outcome}`) }}</dd>
      </div>
      <div v-if="deal.outcome === 'lost'">
        <dt class="text-muted">{{ $t('estimates.deal.lost_reason') }}</dt>
        <dd class="text-heading">{{ lostReason }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ $t('estimates.deal.owner') }}</dt>
        <dd class="text-heading">
          {{ deal.owner?.name ?? '—' }}<span v-if="deal.owner?.sales_team" class="text-muted"> · {{ deal.owner.sales_team }}</span>
        </dd>
      </div>
      <div v-for="row in dates" :key="row.key">
        <dt class="text-muted">{{ row.label }}</dt>
        <dd class="text-heading">{{ row.value }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ $t('estimates.deal.edits_after_send') }}</dt>
        <dd class="text-heading">
          {{ deal.revision_count }}
          <span class="text-muted">({{ deal.price_change_count }} {{ $t('estimates.deal.price_changes') }})</span>
        </dd>
      </div>
      <div>
        <dt class="text-muted">{{ $t('estimates.deal.first_total') }}</dt>
        <dd class="text-heading">{{ money(deal.first_total_minor) }}</dd>
      </div>
      <div>
        <dt class="text-muted">{{ $t('estimates.deal.final_total') }}</dt>
        <dd class="text-heading">
          {{ money(deal.final_total_minor) }}
          <span v-if="discount" class="text-muted">({{ discount }})</span>
        </dd>
      </div>
    </dl>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { dealService } from '@/scripts/api/services/deal.service'
import { useEstimateStore } from '@/scripts/features/company/estimates/store'
import type { DealLifecycle } from '@/scripts/types/domain/deal'

interface Props {
  estimateId: number
}

const props = defineProps<Props>()
const { t, te } = useI18n()
const estimateStore = useEstimateStore()

const deal = ref<DealLifecycle | null>(null)

async function load(): Promise<void> {
  deal.value = (await dealService.lifecycle(props.estimateId)).data
}

const outcomeClass = computed<string>(() => {
  if (deal.value?.outcome === 'won') return 'text-green-600'
  if (deal.value?.outcome === 'lost' || deal.value?.outcome === 'expired') return 'text-red-600'
  return 'text-heading'
})

const lostReason = computed<string>(() => {
  const code = deal.value?.lost_reason
  if (!code) return '—'
  return te(`estimates.lost_reasons.${code}`) ? t(`estimates.lost_reasons.${code}`) : code
})

const discount = computed<string>(() => {
  const pct = deal.value?.discount_pct_first_to_final
  if (pct === null || pct === undefined || pct === 0) return ''
  return `${pct > 0 ? '−' : '+'}${Math.abs(pct).toFixed(1)}%`
})

function day(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString() : t('estimates.deal.not_yet')
}

function money(minor: number | null): string {
  if (minor === null) return '—'
  return `${(minor / 100).toFixed(2)} ${deal.value?.currency ?? ''}`.trim()
}

const dates = computed(() => [
  { key: 'sent', label: t('estimates.deal.first_sent'), value: day(deal.value?.first_sent_at ?? null) },
  { key: 'accepted', label: t('estimates.deal.accepted'), value: day(deal.value?.accepted_at ?? null) },
  { key: 'invoiced', label: t('estimates.deal.invoiced'), value: day(deal.value?.invoiced_at ?? null) },
  { key: 'first_paid', label: t('estimates.deal.first_paid'), value: day(deal.value?.first_paid_at ?? null) },
  { key: 'fully_paid', label: t('estimates.deal.fully_paid'), value: day(deal.value?.fully_paid_at ?? null) },
])

watch(() => [props.estimateId, estimateStore.dealVersion], load)

onMounted(load)
</script>
