<script setup>
import { ref } from 'vue'

const props = defineProps({
  folder: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  activeId: { type: [Number, String], default: null },
  icons: { type: Object, required: true },
})

const emit = defineEmits(['select', 'drop-message'])

// Starred is a virtual, cross-folder view (CLAUDE.md §8) — a message can't
// be "moved into" it, so it's not a valid drop target.
const isDroppable = props.folder.type !== 'starred'
const isDragOver = ref(false)

function handleDragOver(event) {
  if (!isDroppable) return
  event.preventDefault()
  isDragOver.value = true
}

function handleDrop(event) {
  isDragOver.value = false
  if (!isDroppable) return
  const messageId = Number(event.dataTransfer.getData('text/plain'))
  if (messageId) emit('drop-message', { folderId: props.folder.id, messageId })
}
</script>

<template>
  <li>
    <button
      type="button"
      class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition"
      :style="{ paddingInlineStart: `${0.5 + depth * 1.25}rem` }"
      :class="[
        folder.id === activeId ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-gray-700 hover:bg-gray-100',
        isDragOver ? 'outline outline-2 outline-indigo-400' : '',
      ]"
      @click="emit('select', folder.id)"
      @dragover="handleDragOver"
      @dragleave="isDragOver = false"
      @drop="handleDrop"
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
        @drop-message="(payload) => emit('drop-message', payload)"
      />
    </ul>
  </li>
</template>
