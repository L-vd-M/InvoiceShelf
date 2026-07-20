<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useInvoiceStore } from '@/scripts/features/company/invoices/store'
import { useUserStore } from '@/scripts/stores/user.store'

const invoiceStore = useInvoiceStore()
const userStore = useUserStore()

const isSaving = ref<boolean>(false)
const selectedTemplate = ref<string>('')

onMounted(async () => {
  await invoiceStore.fetchInvoiceTemplates()
  selectedTemplate.value =
    userStore.currentUserSettings.default_invoice_template ??
    invoiceStore.templates[0]?.name ??
    ''
})

async function chooseTemplate(name: string): Promise<void> {
  selectedTemplate.value = name
  isSaving.value = true

  await userStore.updateUserSettings({
    settings: { default_invoice_template: name },
  })

  isSaving.value = false
}

function getTickImage(): string {
  return new URL('$images/tick.png', import.meta.url).href
}
</script>

<template>
  <BaseSettingCard
    :title="$t('settings.customization.invoices.default_template')"
    :description="$t('settings.customization.invoices.default_template_description')"
  >
    <div class="grid grid-cols-3 gap-2 p-1 overflow-x-auto mt-2">
      <div
        v-for="(template, index) in invoiceStore.templates"
        :key="index"
        :class="{
          'border border-solid border-primary-500':
            selectedTemplate === template.name,
        }"
        class="
          relative
          flex flex-col
          m-2
          border border-line-default border-solid
          cursor-pointer
          hover:border-primary-300
        "
        @click="chooseTemplate(template.name)"
      >
        <img
          :src="template.path"
          :alt="template.name"
          class="w-full min-h-[100px]"
        />
        <img
          v-if="selectedTemplate === template.name"
          :alt="template.name"
          class="absolute z-10 w-5 h-5 text-primary-500"
          style="top: -6px; right: -5px"
          :src="getTickImage()"
        />
        <span
          :class="[
            'w-full p-1 bg-surface-muted text-sm text-center absolute bottom-0 left-0',
            {
              'text-primary-500 bg-primary-100':
                selectedTemplate === template.name,
              'text-body': selectedTemplate !== template.name,
            },
          ]"
        >
          {{ template.name }}
        </span>
      </div>
    </div>
  </BaseSettingCard>
</template>
