<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { required, helpers } from '@vuelidate/validators'
import useVuelidate from '@vuelidate/core'
import { useModalStore } from '@/scripts/stores/modal.store'
import { useGlobalStore } from '@/scripts/stores/global.store'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { customerCompanyService } from '@/scripts/api/services/customer-company.service'
import type { CustomerCompanyPayload } from '@/scripts/api/services/customer-company.service'

interface CustomerCompanyForm {
  id: number | null
  name: string
  tax_id: string
  vat_id: string
  phone: string
  website: string
  address_street_1: string
  address_street_2: string
  city: string
  state: string
  country_id: number | null
  zip: string
}

const modalStore = useModalStore()
const globalStore = useGlobalStore()
const notificationStore = useNotificationStore()
const { t } = useI18n()

const isSaving = ref<boolean>(false)
const isEdit = ref<boolean>(false)

function emptyForm(): CustomerCompanyForm {
  return {
    id: null,
    name: '',
    tax_id: '',
    vat_id: '',
    phone: '',
    website: '',
    address_street_1: '',
    address_street_2: '',
    city: '',
    state: '',
    country_id: null,
    zip: '',
  }
}

const currentCompany = ref<CustomerCompanyForm>(emptyForm())

const modalActive = computed<boolean>(
  () => modalStore.active && modalStore.componentName === 'CustomerCompanyModal'
)

const rules = computed(() => ({
  name: {
    required: helpers.withMessage(t('validation.required'), required),
  },
}))

const v$ = useVuelidate(rules, currentCompany)

async function setInitialData(): Promise<void> {
  globalStore.fetchCountries()

  if (modalStore.data && typeof modalStore.data === 'number') {
    isEdit.value = true
    const response = await customerCompanyService.get(modalStore.data)
    if (response.data) {
      const c = response.data
      currentCompany.value = {
        id: c.id,
        name: c.name,
        tax_id: c.tax_id ?? '',
        vat_id: c.vat_id ?? '',
        phone: c.phone ?? '',
        website: c.website ?? '',
        address_street_1: c.address?.address_street_1 ?? '',
        address_street_2: c.address?.address_street_2 ?? '',
        city: c.address?.city ?? '',
        state: c.address?.state ?? '',
        country_id: c.address?.country_id ?? null,
        zip: c.address?.zip ?? '',
      }
    }
  } else {
    isEdit.value = false
    currentCompany.value = emptyForm()
  }
}

async function submitCompanyData(): Promise<void> {
  v$.value.$touch()
  if (v$.value.$invalid) {
    return
  }

  isSaving.value = true
  try {
    const payload: CustomerCompanyPayload = {
      name: currentCompany.value.name,
      tax_id: currentCompany.value.tax_id || null,
      vat_id: currentCompany.value.vat_id || null,
      phone: currentCompany.value.phone || null,
      website: currentCompany.value.website || null,
      address: {
        address_street_1: currentCompany.value.address_street_1 || null,
        address_street_2: currentCompany.value.address_street_2 || null,
        city: currentCompany.value.city || null,
        state: currentCompany.value.state || null,
        country_id: currentCompany.value.country_id,
        zip: currentCompany.value.zip || null,
      },
    }

    let saved

    if (isEdit.value && currentCompany.value.id) {
      saved = await customerCompanyService.update(currentCompany.value.id, payload)
      notificationStore.showNotification({
        type: 'success',
        message: 'general.updated_successfully',
      })
    } else {
      saved = await customerCompanyService.create(payload)
      notificationStore.showNotification({
        type: 'success',
        message: 'general.added_successfully',
      })
    }

    isSaving.value = false

    if (modalStore.refreshData) {
      modalStore.refreshData(saved?.data)
    }

    closeModal()
  } catch {
    isSaving.value = false
  }
}

function closeModal(): void {
  modalStore.closeModal()
  setTimeout(() => {
    currentCompany.value = emptyForm()
    isEdit.value = false
    v$.value.$reset()
  }, 300)
}
</script>

<template>
  <BaseModal :show="modalActive" @close="closeModal" @open="setInitialData">
    <template #header>
      <div class="flex justify-between w-full">
        {{ modalStore.title }}
        <BaseIcon
          name="XMarkIcon"
          class="h-6 w-6 text-muted cursor-pointer"
          @click="closeModal"
        />
      </div>
    </template>
    <form action="" @submit.prevent="submitCompanyData">
      <div class="p-4 sm:p-6">
        <BaseInputGrid layout="one-column">
          <BaseInputGroup
            label="Company Name"
            variant="horizontal"
            :error="v$.name.$error && v$.name.$errors[0].$message"
            required
          >
            <BaseInput
              v-model="currentCompany.name"
              :invalid="v$.name.$error"
              type="text"
              @input="v$.name.$touch()"
            />
          </BaseInputGroup>

          <BaseInputGroup label="Tax ID" variant="horizontal">
            <BaseInput v-model="currentCompany.tax_id" type="text" />
          </BaseInputGroup>

          <BaseInputGroup label="VAT ID" variant="horizontal">
            <BaseInput v-model="currentCompany.vat_id" type="text" />
          </BaseInputGroup>

          <BaseInputGroup label="Phone" variant="horizontal">
            <BaseInput v-model="currentCompany.phone" type="text" />
          </BaseInputGroup>

          <BaseInputGroup label="Website" variant="horizontal">
            <BaseInput v-model="currentCompany.website" type="text" />
          </BaseInputGroup>

          <BaseInputGroup label="Address" variant="horizontal">
            <BaseInput
              v-model="currentCompany.address_street_1"
              class="mb-2"
              placeholder="Street 1"
              type="text"
            />
            <BaseInput
              v-model="currentCompany.address_street_2"
              placeholder="Street 2"
              type="text"
            />
          </BaseInputGroup>

          <BaseInputGroup label="Country" variant="horizontal">
            <BaseMultiselect
              v-model="currentCompany.country_id"
              label="name"
              value-prop="id"
              :options="globalStore.countries"
              :can-deselect="true"
              searchable
              track-by="name"
            />
          </BaseInputGroup>

          <BaseInputGroup label="State" variant="horizontal">
            <BaseInput v-model="currentCompany.state" type="text" />
          </BaseInputGroup>

          <BaseInputGroup label="City" variant="horizontal">
            <BaseInput v-model="currentCompany.city" type="text" />
          </BaseInputGroup>

          <BaseInputGroup label="Zip" variant="horizontal">
            <BaseInput v-model="currentCompany.zip" type="text" />
          </BaseInputGroup>
        </BaseInputGrid>
      </div>
      <div
        class="z-0 flex justify-end p-4 border-t border-solid border-line-default"
      >
        <BaseButton
          class="mr-3 text-sm"
          variant="primary-outline"
          type="button"
          @click="closeModal"
        >
          {{ $t('general.cancel') }}
        </BaseButton>
        <BaseButton
          :loading="isSaving"
          :disabled="isSaving"
          variant="primary"
          type="submit"
        >
          <template #left="slotProps">
            <BaseIcon
              v-if="!isSaving"
              name="ArrowDownOnSquareIcon"
              :class="slotProps.class"
            />
          </template>
          {{ isEdit ? $t('general.update') : $t('general.save') }}
        </BaseButton>
      </div>
    </form>
  </BaseModal>
</template>
