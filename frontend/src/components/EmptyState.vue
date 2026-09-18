<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
})
</script>

<template>
  <div class="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center">
    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-50 text-gray-300">
      <slot name="icon">
        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
        </svg>
      </slot>
    </div>
    <p class="font-semibold text-gray-600">{{ title }}</p>
    <p v-if="description" class="max-w-xs text-sm text-gray-400">{{ description }}</p>
  </div>
</template>
