<template>
  <span
    class="
      px-2
      py-1
      text-sm
      font-normal
      text-center text-status-green
      uppercase
      bg-success
    "
    :style="{ backgroundColor: bgColor ?? undefined, color: color ?? undefined }"
  >
    <slot />
  </span>
</template>

<script setup lang="ts">
interface Props {
  bgColor?: string | null
  color?: string | null
}

withDefaults(defineProps<Props>(), {
  bgColor: null,
  color: null,
})
</script>
