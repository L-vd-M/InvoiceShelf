<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useModalStore } from '../../../../stores/modal.store'
import { useDialogStore } from '../../../../stores/dialog.store'
import { useUserStore } from '../../../../stores/user.store'
import { useNotificationStore } from '../../../../stores/notification.store'
import { customerCompanyService } from '@/scripts/api/services/customer-company.service'
import CustomerCompanyModal from '../components/CustomerCompanyModal.vue'

interface TableColumn {
  key: string
  label?: string
  thClass?: string
  tdClass?: string
  sortable?: boolean
}

interface FetchParams {
  page: number
  filter: Record<string, unknown>
  sort: { fieldName: string; order: string }
}

interface FetchResult {
  data: unknown[]
  pagination: {
    totalPages: number
    currentPage: number
    totalCount: number
    limit: number
  }
}

const ABILITIES = {
  CREATE_CUSTOMER_COMPANY: 'create-customer-company',
  EDIT_CUSTOMER_COMPANY: 'edit-customer-company',
  DELETE_CUSTOMER_COMPANY: 'delete-customer-company',
} as const

const { t } = useI18n()
const modalStore = useModalStore()
const dialogStore = useDialogStore()
const userStore = useUserStore()
const notificationStore = useNotificationStore()

const table = ref<{ refresh: () => void } | null>(null)

const columns: TableColumn[] = [
  {
    key: 'name',
    label: 'Company Name',
    thClass: 'extra',
    tdClass: 'font-medium text-heading',
  },
  {
    key: 'tax_id',
    label: 'Tax ID',
  },
  {
    key: 'vat_id',
    label: 'VAT ID',
  },
  {
    key: 'customers_count',
    label: 'Contacts',
  },
  {
    key: 'actions',
    label: '',
    tdClass: 'text-right text-sm font-medium',
    sortable: false,
  },
]

async function fetchData({ page, sort }: FetchParams): Promise<FetchResult> {
  const data = {
    orderByField: sort.fieldName || 'created_at',
    orderBy: sort.order || 'desc',
    page,
  }

  const response = await customerCompanyService.list(data)
  const meta = (response as unknown as { meta: { last_page: number; total: number } }).meta

  return {
    data: response.data ?? [],
    pagination: {
      totalPages: meta?.last_page ?? 1,
      currentPage: page,
      totalCount: meta?.total ?? (response.data?.length ?? 0),
      limit: 10,
    },
  }
}

function refreshTable(): void {
  table.value?.refresh()
}

function openAddModal(): void {
  modalStore.openModal({
    title: 'Add New Company',
    componentName: 'CustomerCompanyModal',
    refreshData: refreshTable,
  })
}

function openEditModal(id: number): void {
  modalStore.openModal({
    title: 'Edit Company',
    componentName: 'CustomerCompanyModal',
    data: id,
    refreshData: refreshTable,
  })
}

function removeCompany(id: number): void {
  dialogStore
    .openDialog({
      title: t('general.are_you_sure'),
      message: 'Deleting this company does not delete its linked customer contacts -- they just lose the company link.',
      yesLabel: t('general.ok'),
      noLabel: t('general.cancel'),
      variant: 'danger',
      hideNoButton: false,
      size: 'lg',
    })
    .then(async (res: boolean) => {
      if (res) {
        await customerCompanyService.delete(id)
        notificationStore.showNotification({
          type: 'success',
          message: 'general.updated_successfully',
        })
        refreshTable()
      }
    })
}
</script>

<template>
  <BasePage>
    <BasePageHeader title="Companies">
      <BaseBreadcrumb>
        <BaseBreadcrumbItem :title="$t('general.home')" to="dashboard" />
        <BaseBreadcrumbItem title="Companies" to="#" active />
      </BaseBreadcrumb>

      <template #actions>
        <BaseButton
          v-if="userStore.hasAbilities(ABILITIES.CREATE_CUSTOMER_COMPANY)"
          @click="openAddModal"
        >
          <template #left="slotProps">
            <BaseIcon name="PlusIcon" :class="slotProps.class" />
          </template>
          New Company
        </BaseButton>
      </template>
    </BasePageHeader>

    <CustomerCompanyModal />

    <BaseTable ref="table" class="mt-5" :data="fetchData" :columns="columns">
      <template #cell-tax_id="{ row }">
        {{ row.data.tax_id || '-' }}
      </template>

      <template #cell-vat_id="{ row }">
        {{ row.data.vat_id || '-' }}
      </template>

      <template #cell-customers_count="{ row }">
        {{ row.data.customers_count ?? 0 }}
      </template>

      <template #cell-actions="{ row }">
        <BaseDropdown>
          <template #activator>
            <BaseIcon name="EllipsisHorizontalIcon" class="h-5 text-muted" />
          </template>

          <BaseDropdownItem
            v-if="userStore.hasAbilities(ABILITIES.EDIT_CUSTOMER_COMPANY)"
            @click="openEditModal(row.data.id)"
          >
            <BaseIcon
              name="PencilIcon"
              class="w-5 h-5 mr-3 text-subtle group-hover:text-muted"
            />
            {{ $t('general.edit') }}
          </BaseDropdownItem>

          <BaseDropdownItem
            v-if="userStore.hasAbilities(ABILITIES.DELETE_CUSTOMER_COMPANY)"
            @click="removeCompany(row.data.id)"
          >
            <BaseIcon
              name="TrashIcon"
              class="w-5 h-5 mr-3 text-subtle group-hover:text-muted"
            />
            {{ $t('general.delete') }}
          </BaseDropdownItem>
        </BaseDropdown>
      </template>
    </BaseTable>
  </BasePage>
</template>
