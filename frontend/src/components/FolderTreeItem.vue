<script setup>
import { ref, nextTick } from 'vue'

const props = defineProps({
  folder: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  activeId: { type: [Number, String], default: null },
  icons: { type: Object, required: true },
  deletingId: { type: [Number, String], default: null },
  renamingId: { type: [Number, String], default: null },
})

const emit = defineEmits(['select', 'drop-message', 'delete', 'rename'])

const isRenaming = ref(false)
const renameValue = ref('')
const renameInput = ref(null)

async function startRenaming() {
  isRenaming.value = true
  renameValue.value = props.folder.display_name
  await nextTick()
  renameInput.value?.focus()
  renameInput.value?.select()
}

function cancelRenaming() {
  isRenaming.value = false
}

function submitRename() {
  // Guards against firing twice — Enter's @submit already calls this and
  // sets isRenaming false, which then triggers the input's own blur event.
  if (!isRenaming.value) return

  const name = renameValue.value.trim()
  isRenaming.value = false
  if (!name || name === props.folder.display_name) return

  emit('rename', { folder: props.folder, name })
}

// Starred is a virtual, cross-folder view (CLAUDE.md §8) — a message can't
// be "moved into" it, so it's not a valid drop target. Sent/Drafts are real
// folders but their contents mean something specific the backend enforces
// (§8 item 66): Sent only ever holds what the account itself actually sent,
// Drafts only ever holds what the compose flow saved — an arbitrary moved
// message would misrepresent both, so neither is offered as a drop target.
const UNMOVABLE_TARGET_TYPES = ['starred', 'sent', 'drafts']
const isDroppable = !UNMOVABLE_TARGET_TYPES.includes(props.folder.type)
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
    <div
      class="group flex items-center gap-1 rounded-xl transition"
      :class="[
        folder.id === activeId
          ? 'bg-indigo-50 font-semibold text-indigo-700 ring-1 ring-indigo-100'
          : 'text-gray-600 hover:bg-gray-50',
        isDragOver ? 'bg-indigo-50 outline outline-2 outline-offset-1 outline-indigo-400' : '',
      ]"
      @dragover="handleDragOver"
      @dragleave="isDragOver = false"
      @drop="handleDrop"
    >
      <button
        v-if="!isRenaming"
        type="button"
        class="flex min-w-0 flex-1 items-center gap-2 px-2 py-1.5 text-sm"
        :style="{ paddingInlineStart: `${0.5 + depth * 1.25}rem` }"
        @click="emit('select', folder.id)"
      >
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="icons[folder.type] ?? icons.custom" />
        </svg>
        <span class="flex-1 truncate text-left" dir="ltr">{{ folder.display_name }}</span>
        <span
          v-if="folder.unread_count > 0"
          class="brand-gradient rounded-full px-1.5 py-0.5 text-[10px] font-bold text-white shadow-sm shadow-indigo-500/30"
        >
          {{ folder.unread_count }}
        </span>
      </button>

      <form
        v-else
        class="flex min-w-0 flex-1 items-center gap-2 py-1"
        :style="{ paddingInlineStart: `${0.5 + depth * 1.25}rem` }"
        @submit.prevent="submitRename"
      >
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="icons[folder.type] ?? icons.custom" />
        </svg>
        <input
          ref="renameInput"
          v-model="renameValue"
          type="text"
          dir="ltr"
          :disabled="renamingId === folder.id"
          class="min-w-0 flex-1 rounded border border-indigo-300 px-1.5 py-0.5 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500"
          @keydown.escape="cancelRenaming"
          @blur="submitRename"
          @click.stop
        />
      </form>

      <button
        v-if="folder.type === 'custom' && !isRenaming"
        type="button"
        :disabled="renamingId === folder.id"
        class="mr-1 shrink-0 rounded p-1 text-gray-300 opacity-0 group-hover:opacity-100 hover:bg-gray-200 hover:text-indigo-500 focus:opacity-100 disabled:cursor-wait disabled:opacity-100"
        aria-label="تغییر نام پوشه"
        @click.stop="startRenaming"
      >
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 18.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125"
          />
        </svg>
      </button>

      <button
        v-if="folder.type === 'custom' && !isRenaming"
        type="button"
        :disabled="deletingId === folder.id"
        class="mr-1 shrink-0 rounded p-1 text-gray-300 opacity-0 group-hover:opacity-100 hover:bg-gray-200 hover:text-red-500 focus:opacity-100 disabled:cursor-wait disabled:opacity-100"
        aria-label="حذف پوشه"
        @click="emit('delete', folder)"
      >
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="m14.74 9-.346 9M9.606 9l.346 9M4.772 5.79A2.25 2.25 0 0 1 6.999 4h9.998a2.25 2.25 0 0 1 2.227 1.79M4.772 5.79 4 5.018M4.772 5.79 6.09 21.243A2.25 2.25 0 0 0 8.334 23h7.332a2.25 2.25 0 0 0 2.244-2.007L19 5.79M19.228 5.79H4.772"
          />
        </svg>
      </button>
    </div>

    <ul v-if="folder.children.length" class="space-y-0.5">
      <FolderTreeItem
        v-for="child in folder.children"
        :key="child.id"
        :folder="child"
        :depth="depth + 1"
        :active-id="activeId"
        :icons="icons"
        :deleting-id="deletingId"
        :renaming-id="renamingId"
        @select="(id) => emit('select', id)"
        @drop-message="(payload) => emit('drop-message', payload)"
        @delete="(folder) => emit('delete', folder)"
        @rename="(payload) => emit('rename', payload)"
      />
    </ul>
  </li>
</template>
