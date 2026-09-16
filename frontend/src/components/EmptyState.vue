<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
})
</script>

<template>
  <div class="flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center text-gray-400">
    <slot name="icon">
      <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
      </svg>
    </slot>
    <p class="font-medium text-gray-500">{{ title }}</p>
    <p v-if="description" class="max-w-xs text-sm">{{ description }}</p>
  </div>
</template>
