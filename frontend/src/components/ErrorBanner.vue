<script setup>
defineProps({
  message: { type: String, required: true },
  retryLabel: { type: String, default: 'تلاش دوباره' },
  showRetry: { type: Boolean, default: true },
})

defineEmits(['retry'])
</script>

<template>
  <div class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
    <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
      <path
        fill-rule="evenodd"
        d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.516-2.625L8.485 2.495ZM10 6a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"
        clip-rule="evenodd"
      />
    </svg>
    <div class="flex-1">
      <p>{{ message }}</p>
      <button
        v-if="showRetry"
        type="button"
        class="mt-2 font-medium text-red-800 underline underline-offset-2 hover:text-red-900"
        @click="$emit('retry')"
      >
        {{ retryLabel }}
      </button>
    </div>
  </div>
</template>
