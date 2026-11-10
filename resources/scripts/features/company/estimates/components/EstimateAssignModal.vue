<template>
  <BaseModal :show="show" @close="emit('close')">
    <template #header>
      <div class="flex justify-between w-full">
        {{ $t('estimates.assign_owner') }}
        <BaseIcon
          name="XMarkIcon"
          class="h-6 w-6 text-muted cursor-pointer"
          @click="emit('close')"
        />
      </div>
    </template>

    <form @submit.prevent="submit">
      <div class="px-8 py-8 sm:p-6">
        <label for="deal-owner" class="block text-sm font-medium text-heading">
          {{ $t('estimates.owner') }}
        </label>
        <select
          id="deal-owner"
          v-model="userId"
          class="mt-1 block w-full rounded-md border border-line-default bg-surface p-2 text-sm text-heading"
        >
          <option :value="0" disabled>{{ $t('estimates.select_owner') }}</option>
          <option v-for="owner in owners" :key="owner.id" :value="owner.id">
            {{ owner.name }}<template v-if="owner.sales_team"> ({{ owner.sales_team }})</template>
          </option>
        </select>
      </div>

      <div class="z-0 flex justify-end px-4 py-4 border-t border-line-default border-solid">
        <BaseButton variant="primary-outline" type="button" class="mr-3" @click="emit('close')">
          {{ $t('general.cancel') }}
        </BaseButton>
        <BaseButton :loading="isSaving" :disabled="isSaving || !userId" variant="primary" type="submit">
          {{ $t('estimates.assign_owner') }}
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
import type { DealOwner } from '../../../../types/domain/deal'

interface Props {
  show: boolean
  estimate: Estimate
}

const props = defineProps<Props>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'updated'): void }>()

const estimateStore = useEstimateStore()
const notificationStore = useNotificationStore()
const { t } = useI18n()

const owners = ref<DealOwner[]>([])
const userId = ref(0)
const isSaving = ref(false)

watch(
  () => props.show,
  async (open) => {
    if (!open) return
    userId.value = 0
    const [list, lifecycle] = await Promise.all([
      dealService.owners(),
      dealService.lifecycle(props.estimate.id),
    ])
    owners.value = list.data
    userId.value = lifecycle.data.owner?.id ?? 0
  },
)

async function submit(): Promise<void> {
  if (!userId.value) return
  isSaving.value = true
  try {
    await estimateStore.assignOwner(props.estimate.id, userId.value)
    notificationStore.showNotification({ type: 'success', message: t('estimates.owner_changed') })
    emit('updated')
    emit('close')
  } finally {
    isSaving.value = false
  }
}
</script>
