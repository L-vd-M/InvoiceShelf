<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { addressService } from '@/scripts/api/services/address.service'
import type { AddressSuggestion } from '@/scripts/api/services/address.service'

interface Props {
  countryCode?: string | null
  uid: string
}

const props = withDefaults(defineProps<Props>(), {
  countryCode: null,
})

const emit = defineEmits<{
  (e: 'select', suggestion: AddressSuggestion): void
}>()

const MIN_CHARS = 4
const DEBOUNCE_MS = 350

const { t } = useI18n()

const query = ref<string>('')
const suggestions = ref<AddressSuggestion[]>([])
const status = ref<string>('')
const activeIndex = ref<number>(-1)
const isOpen = computed<boolean>(() => suggestions.value.length > 0)

let timer: ReturnType<typeof setTimeout> | null = null
let controller: AbortController | null = null

const listId = computed(() => `addr-list-${props.uid}`)
const optionId = (i: number): string => `${listId.value}-${i}`

function close(): void {
  suggestions.value = []
  activeIndex.value = -1
}

async function search(): Promise<void> {
  const q = query.value.trim()
  if (q.length < MIN_CHARS) {
    close()
    status.value = ''
    return
  }

  controller?.abort()
  controller = new AbortController()
  status.value = ''

  try {
    const data = await addressService.suggest(
      q,
      props.countryCode ?? undefined,
      controller.signal
    )
    if (data.error) {
      status.value = t('customers.address_search_unavailable')
      close()
      return
    }
    suggestions.value = data.suggestions
    activeIndex.value = -1
    status.value = data.suggestions.length
      ? ''
      : t('customers.address_search_none')
  } catch (e) {
    if ((e as { code?: string }).code === 'ERR_CANCELED') return
    const httpStatus = (e as { response?: { status?: number } }).response
      ?.status
    status.value =
      httpStatus === 429
        ? t('customers.address_search_slow_down')
        : t('customers.address_search_unavailable')
    close()
  }
}

function onInput(): void {
  if (timer) clearTimeout(timer)
  timer = setTimeout(search, DEBOUNCE_MS)
}

function pick(s: AddressSuggestion): void {
  query.value = s.label
  status.value = t('customers.address_search_filled')
  close()
  emit('select', s)
}

function move(step: number): void {
  const n = suggestions.value.length
  if (!n) return
  activeIndex.value = (activeIndex.value + step + n) % n
}

function onEnter(e: KeyboardEvent): void {
  if (activeIndex.value >= 0) {
    e.preventDefault()
    pick(suggestions.value[activeIndex.value])
  }
}

function onBlur(): void {
  setTimeout(close, 150)
}

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
  controller?.abort()
})
</script>

<template>
  <div class="relative">
    <BaseInputGroup
      :label="$t('customers.address_search')"
      :help-text="$t('customers.address_search_hint')"
    >
      <input
        v-model="query"
        type="text"
        role="combobox"
        autocomplete="off"
        maxlength="200"
        :aria-expanded="isOpen"
        :aria-controls="listId"
        :aria-activedescendant="activeIndex >= 0 ? optionId(activeIndex) : undefined"
        aria-autocomplete="list"
        :placeholder="$t('customers.address_search_placeholder')"
        class="block w-full px-3 py-2 text-sm border border-gray-200 rounded-md shadow-xs focus:outline-hidden focus:ring-1 focus:ring-primary-400 focus:border-primary-400 mt-1 md:mt-0"
        @input="onInput"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter="onEnter"
        @keydown.esc="close"
        @blur="onBlur"
      />
    </BaseInputGroup>

    <ul
      v-show="isOpen"
      :id="listId"
      role="listbox"
      class="absolute z-20 w-full mt-1 overflow-auto bg-white border border-gray-200 rounded-md shadow-lg max-h-60"
    >
      <li
        v-for="(s, i) in suggestions"
        :id="optionId(i)"
        :key="i"
        role="option"
        :aria-selected="i === activeIndex"
        class="px-3 py-2 text-sm cursor-pointer"
        :class="i === activeIndex ? 'bg-primary-50' : 'hover:bg-gray-50'"
        @mousedown.prevent="pick(s)"
      >
        {{ s.label }}
      </li>
    </ul>

    <p class="mt-1 text-xs text-gray-500" role="status" aria-live="polite">
      {{ status }}
    </p>
    <p class="mt-1 text-xs text-gray-400">
      {{ $t('customers.address_search_credit') }}
    </p>
  </div>
</template>
