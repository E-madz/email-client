<script setup>
defineProps({
  folder: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  activeId: { type: Number, default: null },
  icons: { type: Object, required: true },
})

const emit = defineEmits(['select'])
</script>

<template>
  <li>
    <button
      type="button"
      class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition"
      :style="{ paddingInlineStart: `${0.5 + depth * 1.25}rem` }"
      :class="folder.id === activeId ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-gray-700 hover:bg-gray-100'"
      @click="emit('select', folder.id)"
    >
      <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" :d="icons[folder.type] ?? icons.custom" />
      </svg>
      <span class="flex-1 truncate text-left" dir="ltr">{{ folder.display_name }}</span>
      <span
        v-if="folder.unread_count > 0"
        class="rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
      >
        {{ folder.unread_count }}
      </span>
    </button>

    <ul v-if="folder.children.length" class="space-y-0.5">
      <FolderTreeItem
        v-for="child in folder.children"
        :key="child.id"
        :folder="child"
        :depth="depth + 1"
        :active-id="activeId"
        :icons="icons"
        @select="(id) => emit('select', id)"
      />
    </ul>
  </li>
</template>
