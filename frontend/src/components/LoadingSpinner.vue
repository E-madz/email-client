<script setup>
defineProps({
  label: { type: String, default: 'در حال بارگذاری…' },
  size: { type: String, default: 'md' }, // 'sm' | 'md' | 'lg'
})

const sizeClasses = {
  sm: 'h-4 w-4 border-2',
  md: 'h-6 w-6 border-2',
  lg: 'h-10 w-10 border-[3px]',
}
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-2 py-6 text-gray-500">
    <span
      class="inline-block animate-spin rounded-full border-indigo-500 border-t-transparent"
      :class="sizeClasses[size]"
      role="status"
    />
    <span v-if="label" class="text-sm">{{ label }}</span>
  </div>
</template>
