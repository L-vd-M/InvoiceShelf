<template>
  <div class="mt-6">
    <p v-if="isLoading" class="text-sm text-muted">{{ $t('revisions.loading') }}</p>
    <p v-else-if="!revisions.length" class="text-sm text-muted">{{ $t('revisions.none') }}</p>

    <ol v-else class="relative border-l border-line-default ml-3">
      <li v-for="revision in revisions" :key="revision.id" class="mb-6 ml-6">
        <span
          class="absolute flex items-center justify-center w-3 h-3 rounded-full -left-1.5 ring-4 ring-surface"
          :class="dotClass(revision.reason_code)"
        />
        <div class="flex flex-wrap items-baseline gap-x-3">
          <span class="text-sm font-semibold text-heading">
            #{{ revision.revision_no }} {{ reasonLabel(revision.reason_code) }}
          </span>
          <span class="text-xs text-muted">{{ formatTime(revision.created_at) }}</span>
          <span class="text-xs text-muted">{{ actorLabel(revision) }}</span>
        </div>

        <p v-if="revision.note" class="mt-1 text-sm text-body">{{ revision.note }}</p>

        <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted">
          <span v-if="revision.diff_summary.changes > 0">
            {{ $t('revisions.changes', { count: revision.diff_summary.changes }) }}
          </span>
          <button
            v-if="revision.diff_summary.changes > 0"
            type="button"
            class="font-medium text-primary-500 hover:underline"
            @click="toggle(revision)"
          >
            {{ expanded[revision.id] ? $t('revisions.hide_changes') : $t('revisions.show_changes') }}
          </button>
          <button
            v-if="revision.has_pdf"
            type="button"
            class="font-medium text-primary-500 hover:underline"
            @click="openPdf(revision)"
          >
            {{ $t('revisions.open_pdf') }}
          </button>
          <span v-if="revision.pdf_sha256" class="font-mono" :title="revision.pdf_sha256">
            SHA-256 {{ revision.pdf_sha256.slice(0, 12) }}
          </span>
        </div>

        <div v-if="expanded[revision.id]" class="mt-2">
          <p v-if="diffs[revision.id] === undefined" class="text-xs text-muted">
            {{ $t('revisions.loading') }}
          </p>
          <table v-else class="w-full text-sm border border-line-default">
            <tbody>
              <tr v-for="(change, i) in diffs[revision.id]" :key="i" class="border-t border-line-default">
                <td class="p-2 align-top text-muted whitespace-nowrap">{{ kindLabel(change.kind) }}</td>
                <td class="p-2 align-top text-heading">{{ changeTarget(change) }}</td>
                <td class="p-2 align-top text-body">
                  <span v-if="change.kind === 'line_added'">{{ show(change, change.after) }}</span>
                  <span v-else-if="change.kind === 'line_removed'" class="line-through">{{ show(change, change.before) }}</span>
                  <template v-else>
                    <span class="line-through">{{ show(change, change.before) }}</span>
                    →
                    <span>{{ show(change, change.after) }}</span>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </li>
    </ol>

    <div v-if="lastPage > 1" class="flex items-center justify-between text-sm">
      <BaseButton variant="primary-outline" size="sm" :disabled="page <= 1" @click="goTo(page - 1)">
        {{ $t('credit_notes.previous') }}
      </BaseButton>
      <span class="text-muted">{{ page }} / {{ lastPage }}</span>
      <BaseButton variant="primary-outline" size="sm" :disabled="page >= lastPage" @click="goTo(page + 1)">
        {{ $t('credit_notes.next') }}
      </BaseButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { revisionService } from '@/scripts/api/services/revision.service'
import type {
  Revision,
  RevisionChange,
  RevisionDocumentType,
} from '@/scripts/types/domain/revision'

interface Props {
  type: RevisionDocumentType
  documentId: number
}

const props = defineProps<Props>()
const { t, te } = useI18n()

const revisions = ref<Revision[]>([])
const isLoading = ref(false)
const page = ref(1)
const lastPage = ref(1)
const expanded = reactive<Record<number, boolean>>({})
const diffs = reactive<Record<number, RevisionChange[] | undefined>>({})

const MONEY_PATHS = ['sub_total', 'tax', 'total', 'discount_val', 'credited_total', 'applied_amount', 'unapplied_amount']

async function load(): Promise<void> {
  isLoading.value = true
  try {
    const response = await revisionService.list(props.type, props.documentId, { page: page.value })
    revisions.value = response.data
    lastPage.value = response.meta.last_page
  } finally {
    isLoading.value = false
  }
}

function goTo(next: number): void {
  page.value = next
  load()
}

async function toggle(revision: Revision): Promise<void> {
  expanded[revision.id] = !expanded[revision.id]

  if (expanded[revision.id] && diffs[revision.id] === undefined) {
    const response = await revisionService.diff(revision.id)
    diffs[revision.id] = response.data.changes
  }
}

async function openPdf(revision: Revision): Promise<void> {
  const blob = await revisionService.pdf(revision.id)
  const url = URL.createObjectURL(blob)
  window.open(url, '_blank')
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

function reasonLabel(code: string): string {
  return te(`revisions.reasons.${code}`) ? t(`revisions.reasons.${code}`) : code
}

function kindLabel(kind: string): string {
  return te(`revisions.kinds.${kind}`) ? t(`revisions.kinds.${kind}`) : kind
}

function actorLabel(revision: Revision): string {
  const who = revision.actor_name ?? t(`revisions.sources.${revision.source}`)
  return revision.actor_role ? `${who} (${revision.actor_role})` : who
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleString()
}

function dotClass(code: string): string {
  if (code === 'issued') return 'bg-primary-500'
  if (code === 'voided' || code === 'rejected' || code === 'expired') return 'bg-red-500'
  if (code === 'accepted' || code === 'converted') return 'bg-green-500'
  return 'bg-line-strong'
}

function changeTarget(change: RevisionChange): string {
  if (change.line) return change.line
  return change.path
}

function minor(value: unknown): string {
  return typeof value === 'number' ? (value / 100).toFixed(2) : String(value ?? '')
}

function show(change: RevisionChange, value: unknown): string {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'object') {
    const line = value as { name?: string; quantity?: string; price?: number }
    return `${line.name ?? ''} × ${line.quantity ?? ''} @ ${minor(line.price)}`
  }
  if (change.kind === 'unit_price_changed' || (change.kind === 'header_changed' && MONEY_PATHS.includes(change.path))) {
    return minor(value)
  }
  return String(value)
}

watch(() => props.documentId, () => {
  page.value = 1
  load()
})

onMounted(load)
</script>
