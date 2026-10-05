<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { required, helpers } from '@vuelidate/validators'
import useVuelidate from '@vuelidate/core'
import { useGlobalStore } from '@/scripts/stores/global.store'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { customerCompanyService } from '@/scripts/api/services/customer-company.service'
import type { CustomerCompanyPayload } from '@/scripts/api/services/customer-company.service'
import type { AddressSuggestion } from '@/scripts/api/services/address.service'

interface CustomerCompanyForm {
  name: string
  phone: string
  website: string
  tax_id: string
  vat_id: string
  country_id: number | null
  state: string
  city: string
  address_street_1: string
  address_street_2: string
  zip: string
}

const route = useRoute()
const router = useRouter()
const globalStore = useGlobalStore()
const notificationStore = useNotificationStore()
const { t } = useI18n()

const isSaving = ref<boolean>(false)
const isFetchingInitialData = ref<boolean>(false)

const isEdit = computed<boolean>(() => route.name === 'customer-companies.edit')
const pageTitle = computed<string>(() =>
  isEdit.value ? 'Edit Company' : 'New Company'
)

function emptyForm(): CustomerCompanyForm {
  return {
    name: '',
    phone: '',
    website: '',
    tax_id: '',
    vat_id: '',
    country_id: null,
    state: '',
    city: '',
    address_street_1: '',
    address_street_2: '',
    zip: '',
  }
}

const form = ref<CustomerCompanyForm>(emptyForm())

const rules = computed(() => ({
  name: {
    required: helpers.withMessage(t('validation.required'), required),
  },
}))

const v$ = useVuelidate(rules, form)

const countryCode = computed<string | null>(
  () =>
    globalStore.countries.find((c) => c.id === form.value.country_id)?.code ??
    null
)

function applyAddressSuggestion(s: AddressSuggestion): void {
  form.value.address_street_1 = s.address_street_1
  form.value.city = s.city
  form.value.state = s.state
  form.value.zip = s.zip
  const country = globalStore.countries.find((c) => c.code === s.country_code)
  if (country) form.value.country_id = country.id
}

onMounted(async () => {
  globalStore.fetchCountries()

  if (!isEdit.value) return

  isFetchingInitialData.value = true
  try {
    const response = await customerCompanyService.get(Number(route.params.id))
    const c = response.data
    if (c) {
      form.value = {
        name: c.name,
        phone: c.phone ?? '',
        website: c.website ?? '',
        tax_id: c.tax_id ?? '',
        vat_id: c.vat_id ?? '',
        country_id: c.address?.country_id ?? null,
        state: c.address?.state ?? '',
        city: c.address?.city ?? '',
        address_street_1: c.address?.address_street_1 ?? '',
        address_street_2: c.address?.address_street_2 ?? '',
        zip: c.address?.zip ?? '',
      }
    }
  } finally {
    isFetchingInitialData.value = false
  }
})

async function submit(): Promise<void> {
  v$.value.$touch()
  if (v$.value.$invalid) return

  isSaving.value = true
  try {
    const f = form.value
    const payload: CustomerCompanyPayload = {
      name: f.name,
      tax_id: f.tax_id || null,
      vat_id: f.vat_id || null,
      phone: f.phone || null,
      website: f.website || null,
      address: {
        address_street_1: f.address_street_1 || null,
        address_street_2: f.address_street_2 || null,
        city: f.city || null,
        state: f.state || null,
        country_id: f.country_id,
        zip: f.zip || null,
      },
    }

    if (isEdit.value) {
      await customerCompanyService.update(Number(route.params.id), payload)
    } else {
      await customerCompanyService.create(payload)
    }

    notificationStore.showNotification({
      type: 'success',
      message: isEdit.value
        ? 'general.updated_successfully'
        : 'general.added_successfully',
    })
    router.push({ name: 'customer-companies.index' })
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <BasePage>
    <form @submit.prevent="submit">
      <BasePageHeader :title="pageTitle">
        <BaseBreadcrumb>
          <BaseBreadcrumbItem :title="$t('general.home')" to="dashboard" />
          <BaseBreadcrumbItem
            title="Companies"
            to="/admin/customer-companies"
          />
          <BaseBreadcrumbItem :title="pageTitle" to="#" active />
        </BaseBreadcrumb>

        <template #actions>
          <div class="flex items-center justify-end">
            <BaseButton type="submit" :loading="isSaving" :disabled="isSaving">
              <template #left="slotProps">
                <BaseIcon
                  name="ArrowDownOnSquareIcon"
                  :class="slotProps.class"
                />
              </template>
              {{ isEdit ? 'Update Company' : 'Save Company' }}
            </BaseButton>
          </div>
        </template>
      </BasePageHeader>

      <BaseCard class="mt-5">
        <!-- Basic Info -->
        <div class="grid grid-cols-5 gap-4 mb-8">
          <h6 class="col-span-5 text-lg font-semibold text-left lg:col-span-1">
            {{ $t('customers.basic_info') }}
          </h6>

          <BaseInputGrid class="col-span-5 lg:col-span-4">
            <BaseInputGroup
              label="Company Name"
              required
              :error="v$.name.$error && v$.name.$errors[0].$message"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="form.name"
                :content-loading="isFetchingInitialData"
                type="text"
                name="name"
                :invalid="v$.name.$error"
                @input="v$.name.$touch()"
              />
            </BaseInputGroup>

            <BaseInputGroup
              :label="$t('customers.phone')"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model.trim="form.phone"
                :content-loading="isFetchingInitialData"
                type="text"
                name="phone"
              />
            </BaseInputGroup>

            <BaseInputGroup
              :label="$t('customers.website')"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="form.website"
                :content-loading="isFetchingInitialData"
                type="text"
                name="website"
              />
            </BaseInputGroup>

            <BaseInputGroup
              label="Tax ID"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="form.tax_id"
                :content-loading="isFetchingInitialData"
                type="text"
                name="tax_id"
              />
            </BaseInputGroup>

            <BaseInputGroup
              label="VAT Number"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="form.vat_id"
                :content-loading="isFetchingInitialData"
                type="text"
                name="vat_id"
              />
            </BaseInputGroup>
          </BaseInputGrid>
        </div>

        <BaseDivider class="mb-5 md:mb-8" />

        <!-- Address -->
        <div class="grid grid-cols-5 gap-4 mb-8">
          <h6 class="col-span-5 text-lg font-semibold text-left lg:col-span-1">
            Address
          </h6>

          <BaseInputGrid class="col-span-5 lg:col-span-4">
            <div class="md:col-span-2">
              <BaseAddressSearch
                uid="company"
                :country-code="countryCode"
                @select="applyAddressSuggestion"
              />
            </div>

            <BaseInputGroup
              :label="$t('customers.country')"
              :content-loading="isFetchingInitialData"
            >
              <BaseMultiselect
                v-model="form.country_id"
                value-prop="id"
                label="name"
                track-by="name"
                resolve-on-load
                searchable
                :content-loading="isFetchingInitialData"
                :options="globalStore.countries"
                :placeholder="$t('general.select_country')"
                class="w-full"
              />
            </BaseInputGroup>

            <BaseInputGroup
              :label="$t('customers.state')"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="form.state"
                :content-loading="isFetchingInitialData"
                type="text"
                name="state"
              />
            </BaseInputGroup>

            <BaseInputGroup
              :label="$t('customers.city')"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="form.city"
                :content-loading="isFetchingInitialData"
                type="text"
                name="city"
              />
            </BaseInputGroup>

            <BaseInputGroup
              :label="$t('customers.zip_code')"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model.trim="form.zip"
                :content-loading="isFetchingInitialData"
                type="text"
                name="zip"
              />
            </BaseInputGroup>

            <BaseInputGroup
              :label="$t('customers.address')"
              :content-loading="isFetchingInitialData"
            >
              <BaseTextarea
                v-model="form.address_street_1"
                :content-loading="isFetchingInitialData"
                :placeholder="$t('general.street_1')"
                rows="2"
                cols="50"
              />
            </BaseInputGroup>

            <BaseInputGroup :content-loading="isFetchingInitialData">
              <BaseTextarea
                v-model="form.address_street_2"
                :content-loading="isFetchingInitialData"
                :placeholder="$t('general.street_2')"
                rows="2"
                cols="50"
              />
            </BaseInputGroup>
          </BaseInputGrid>
        </div>
      </BaseCard>
    </form>
  </BasePage>
</template>
