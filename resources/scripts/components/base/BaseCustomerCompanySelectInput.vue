<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useModalStore } from '../../stores/modal.store'
import { useUserStore } from '../../stores/user.store'
import { ABILITIES } from '../../config/abilities'
import { customerCompanyService } from '@/scripts/api/services/customer-company.service'
import CustomerCompanyModal from '../../features/company/customers/components/CustomerCompanyModal.vue'

interface Props {
  modelValue?: number | null
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: null,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | null): void
}>()

const { t } = useI18n()
const modalStore = useModalStore()
const userStore = useUserStore()

// BaseMultiselect only calls its async `options` loader once, on mount (or
// when the user types a search query) -- it has no way to know a brand new
// company was just created elsewhere, so the freshly-created item can't
// resolve to a label and shows as a bare id instead. Bumping this key forces
// a full remount, which re-triggers the initial load and picks it up.
const selectKey = ref<number>(0)

async function searchCustomerCompanies(search: string) {
  const response = await customerCompanyService.list({ search, limit: 'all' } as never)
  return response.data ?? []
}

function addCustomerCompany(): void {
  modalStore.openModal({
    title: 'Add New Company',
    componentName: 'CustomerCompanyModal',
    data: null,
    refreshData: (created: { id: number } | undefined) => {
      if (created?.id) {
        emit('update:modelValue', created.id)
        selectKey.value += 1
      }
    },
  })
}
</script>

<template>
  <BaseMultiselect
    :key="selectKey"
    :model-value="props.modelValue"
    v-bind="$attrs"
    track-by="name"
    value-prop="id"
    label="name"
    :filter-results="false"
    resolve-on-load
    :delay="500"
    :searchable="true"
    :options="searchCustomerCompanies"
    label-value="name"
    placeholder="Search or select a company"
    :can-deselect="true"
    class="w-full"
    @update:model-value="(value) => emit('update:modelValue', value as number | null)"
  >
    <template #action>
      <BaseSelectAction
        v-if="userStore.hasAbilities(ABILITIES.CREATE_CUSTOMER_COMPANY)"
        @click="addCustomerCompany"
      >
        <BaseIcon
          name="BuildingOfficeIcon"
          class="h-4 mr-2 -ml-2 text-center text-primary-400"
        />
        Add new company
      </BaseSelectAction>
    </template>
  </BaseMultiselect>

  <CustomerCompanyModal />
</template>
