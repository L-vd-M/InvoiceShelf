<template>
  <BaseModal :show="show" @close="emit('close')">
    <template #header>
      <div class="flex justify-between w-full">
        {{ $t('estimates.mark_as_lost') }}
        <BaseIcon
          name="XMarkIcon"
          class="h-6 w-6 text-muted cursor-pointer"
          @click="emit('close')"
        />
      </div>
    </template>

    <form @submit.prevent="submit">
      <div class="px-8 py-8 sm:p-6">
        <p class="mb-4 text-sm text-body">{{ $t('estimates.lost_description') }}</p>

        <label for="lost-reason" class="block text-sm font-medium text-heading">
          {{ $t('estimates.lost_reason') }}
        </label>
        <select
          id="lost-reason"
          v-model="reasonCode"
          class="mt-1 block w-full rounded-md border border-line-default bg-surface p-2 text-sm text-heading"
        >
          <option value="" disabled>{{ $t('general.deletion_reason_choose') }}</option>
          <option v-for="code in reasons" :key="code" :value="code">
            {{ reasonLabel(code) }}
          </option>
        </select>

        <label for="lost-note" class="block mt-4 text-sm font-medium text-heading">
          {{ $t('estimates.lost_note') }}
        </label>
        <textarea
          id="lost-note"
          v-model="note"
          rows="3"
          maxlength="500"
          class="mt-1 block w-full rounded-md border border-line-default bg-surface p-2 text-sm text-heading"
        />
      </div>

      <div class="z-0 flex justify-end px-4 py-4 border-t border-line-default border-solid">
        <BaseButton variant="primary-outline" type="button" class="mr-3" @click="emit('close')">
          {{ $t('general.cancel') }}
        </BaseButton>
        <BaseButton :loading="isSaving" :disabled="isSaving || !reasonCode" variant="danger" type="submit">
          {{ $t('estimates.mark_as_lost') }}
        </BaseButton>
      </div>
    </form>
  </BaseModal>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEstimateStore } from '../store'
import { dealService } from '../../../../api/services/deal.service'
import { useNotificationStore } from '../../../../stores/notification.store'
import type { Estimate } from '../../../../types/domain/estimate'

interface Props {
  show: boolean
  estimate: Estimate
}

const props = defineProps<Props>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'updated'): void }>()

const estimateStore = useEstimateStore()
const notificationStore = useNotificationStore()
const { t, te } = useI18n()

const reasons = ref<string[]>([])
const reasonCode = ref('')
const note = ref('')
const isSaving = ref(false)

function reasonLabel(code: string): string {
  return te(`estimates.lost_reasons.${code}`) ? t(`estimates.lost_reasons.${code}`) : code
}

watch(
  () => props.show,
  async (open) => {
    if (!open) return
    reasonCode.value = ''
    note.value = ''
    if (!reasons.value.length) {
      reasons.value = (await dealService.lostReasons()).data
    }
  },
)

async function submit(): Promise<void> {
  if (!reasonCode.value) return
  isSaving.value = true
  try {
    await estimateStore.markLost(props.estimate.id, {
      reason_code: reasonCode.value,
      note: note.value.trim() || null,
    })
    notificationStore.showNotification({ type: 'success', message: t('estimates.deal_marked_lost') })
    emit('updated')
    emit('close')
  } finally {
    isSaving.value = false
  }
}
</script>
