<script setup>
import { ref, watch, onMounted, computed, nextTick } from 'vue'
import { useFoldersStore, STARRED_ID } from '../stores/folders'
import { useMessagesStore } from '../stores/messages'
import LoadingSpinner from './LoadingSpinner.vue'
import ErrorBanner from './ErrorBanner.vue'
import FolderTreeItem from './FolderTreeItem.vue'

const props = defineProps({
  accountId: { type: Number, default: null },
})

const emit = defineEmits(['select'])

const folders = useFoldersStore()
const messages = useMessagesStore()

const isCreating = ref(false)
const newFolderName = ref('')
const newFolderInput = ref(null)

async function startCreating() {
  isCreating.value = true
  newFolderName.value = ''
  await nextTick()
  newFolderInput.value?.focus()
}

function cancelCreating() {
  isCreating.value = false
}

async function submitNewFolder() {
  const name = newFolderName.value.trim()
  if (!name || !props.accountId) return

  try {
    await folders.createFolder(props.accountId, name)
    isCreating.value = false
  } catch {
    // folders.createError already holds the message; the input stays open
    // so the user can see the error banner and retry without retyping.
  }
}

function handleDropMessage({ folderId, messageId }) {
  messages.moveMessage(messageId, folderId)
}

const deletingFolderId = ref(null)
const deleteError = ref(null)

async function handleDeleteFolder(folder) {
  const warning =
    folder.unread_count > 0
      ? `پوشه‌ی «${folder.display_name}» حذف شود؟ این پوشه پیام‌های خوانده‌نشده دارد و همه‌ی پیام‌های داخل آن هم برای همیشه حذف می‌شوند. این کار قابل بازگشت نیست.`
      : `پوشه‌ی «${folder.display_name}» حذف شود؟ اگر پیامی داخل آن باشد، همراه با پوشه برای همیشه حذف می‌شود. این کار قابل بازگشت نیست.`

  if (!confirm(warning)) return

  deletingFolderId.value = folder.id
  deleteError.value = null

  try {
    await folders.deleteFolder(folder.id)
  } catch (error) {
    deleteError.value = error
  } finally {
    deletingFolderId.value = null
  }
}

const FOLDER_ICONS = {
  inbox:
    'M3 12h4.5l1.5 3h6l1.5-3H21M3 12v6.75A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V12M3 12l2.25-6.75A1.5 1.5 0 0 1 6.68 4.25h10.64a1.5 1.5 0 0 1 1.43 1L21 12',
  sent: 'M6 12 3.269 3.126A59.77 59.77 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.876L5.999 12Zm0 0h7.5',
  drafts: 'M16.862 4.487 18.549 2.8a2.121 2.121 0 1 1 3 3l-1.687 1.688M16.862 4.487 5.03 16.319l-4.132.918.918-4.132L16.862 4.487Z',
  trash:
    'M14.74 9-.346 9M9.26 9l.346 9M4.772 5.79 5.79 4.772A2.25 2.25 0 0 1 7.379 4h9.242a2.25 2.25 0 0 1 1.59.772l1.017 1.017M4.772 5.79 4 5.018M4.772 5.79 6.09 21.243A2.25 2.25 0 0 0 8.334 23h7.332a2.25 2.25 0 0 0 2.244-2.007L19 5.79M4.772 5.79H19.228',
  spam: 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
  archive:
    'M20.25 7.5 18.75 6H5.25L3.75 7.5m16.5 0v10.5A1.5 1.5 0 0 1 18.75 19.5H5.25a1.5 1.5 0 0 1-1.5-1.5V7.5m16.5 0h-16.5m6.75 3h3',
  custom: 'M3.75 9.75h16.5M3.75 9.75V19.5a1.5 1.5 0 0 0 1.5 1.5h13.5a1.5 1.5 0 0 0 1.5-1.5V9.75M3.75 9.75l1.5-5.25h13.5l1.5 5.25',
  starred: 'M11.48 3.499 13.702 8l4.966.723-3.594 3.503.848 4.946L11.48 14.75l-4.442 2.421.848-4.946L4.29 8.722l4.966-.723 2.223-4.5Z',
}

/**
 * Builds a display-only tree from the flat folder list the backend returns
 * (CLAUDE.md §3.3 — folders are stored/synced flat, no parent_id column).
 * A folder's parent is inferred from its own raw IMAP `name`: e.g.
 * "INBOX.spam" nests under "INBOX" because "INBOX" + one of the common
 * hierarchy delimiters (".", "/") is a literal prefix of its name. This
 * mirrors what the mail server/Roundcube actually shows (spam as a child of
 * Inbox) without needing to persist or sync any hierarchy ourselves — a
 * folder whose inferred parent doesn't actually exist in the list just stays
 * top-level instead of inventing structure.
 */
function buildFolderTree(items) {
  const byName = new Map(items.map((folder) => [folder.name, folder]))
  const childrenByParentId = new Map()
  const roots = []

  function findParent(folder) {
    for (const delimiter of ['.', '/']) {
      const index = folder.name.lastIndexOf(delimiter)
      if (index > 0) {
        const parent = byName.get(folder.name.slice(0, index))
        if (parent) return parent
      }
    }
    return null
  }

  for (const folder of items) {
    const parent = findParent(folder)
    if (parent) {
      if (!childrenByParentId.has(parent.id)) childrenByParentId.set(parent.id, [])
      childrenByParentId.get(parent.id).push(folder)
    } else {
      roots.push(folder)
    }
  }

  function attach(folder) {
    return { ...folder, children: (childrenByParentId.get(folder.id) ?? []).map(attach) }
  }

  return roots.map(attach)
}

const folderTree = computed(() => buildFolderTree(folders.items))

/**
 * Split the tree into system folders (Inbox/Sent/Drafts/Trash/Spam/Archive)
 * and folders the user created themselves (`type === 'custom'`), so the
 * sidebar can show a visual divider between the two groups. This is the
 * same flag `FolderController::destroy` gates deletion on (CLAUDE.md §8
 * item 57) — correct once `FolderSyncService`'s dedup rule trusts the
 * server's own SPECIAL-USE attribute over a name-only match, so a folder
 * that only looks like "spam"/"trash" by name without the real protocol
 * flag is genuinely `custom` and belongs in this group. A user-created
 * folder is never expected to nest under another folder anyway, since new
 * folders are always created top-level (§8 item 52).
 */
const systemFolderRoots = computed(() => folderTree.value.filter((folder) => folder.type !== 'custom'))
const userFolderRoots = computed(() => folderTree.value.filter((folder) => folder.type === 'custom'))

function loadFolders() {
  if (props.accountId) {
    folders.fetch(props.accountId)
  } else {
    folders.reset()
  }
}

function selectFolder(folderId) {
  folders.setActive(folderId)
  emit('select', folderId)
}

watch(() => props.accountId, loadFolders)
onMounted(loadFolders)
</script>

<template>
  <div>
    <div class="flex items-center justify-between px-1 pb-2 pt-4">
      <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">پوشه‌ها</h2>
      <div class="flex items-center gap-1">
        <button
          type="button"
          class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 disabled:opacity-40"
          aria-label="ساخت پوشه‌ی جدید"
          :disabled="!accountId"
          @click="startCreating"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
        </button>
        <button
          type="button"
          class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 disabled:opacity-40"
          aria-label="به‌روزرسانی پوشه‌ها"
          :disabled="!accountId || folders.loadingAccountId === accountId"
          @click="loadFolders"
        >
          <svg
            class="h-3.5 w-3.5"
            :class="{ 'animate-spin': accountId && folders.loadingAccountId === accountId }"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"
            />
          </svg>
        </button>
      </div>
    </div>

    <form v-if="isCreating" class="mb-2 px-1" @submit.prevent="submitNewFolder">
      <input
        ref="newFolderInput"
        v-model="newFolderName"
        type="text"
        placeholder="نام پوشه"
        :disabled="folders.creating"
        class="w-full rounded-lg border border-gray-200 px-2 py-1 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
        @keydown.escape="cancelCreating"
        @blur="!newFolderName && cancelCreating()"
      />
      <p v-if="folders.createError" class="mt-1 text-xs text-red-600">
        {{ folders.createError?.message ?? 'ساخت پوشه با خطا مواجه شد.' }}
      </p>
    </form>

    <!--
      Starred is a virtual, cross-folder view (CLAUDE.md §8) — not a real
      synced IMAP folder, so it's shown independently of the live folder
      sync's loading/error state below.
    -->
    <ul v-if="accountId" class="mb-1 space-y-0.5">
      <FolderTreeItem
        :folder="{ id: STARRED_ID, type: 'starred', display_name: 'Starred', unread_count: 0, children: [] }"
        :active-id="folders.activeId"
        :icons="FOLDER_ICONS"
        @select="selectFolder"
      />
    </ul>

    <LoadingSpinner
      v-if="accountId && folders.loadingAccountId === accountId && !folders.items.length"
      size="sm"
      label=""
    />
    <ErrorBanner
      v-else-if="folders.error"
      message="دریافت پوشه‌ها با خطا مواجه شد."
      @retry="loadFolders"
    />
    <p v-else-if="!accountId" class="px-1 text-sm text-gray-400">ابتدا یک اکانت انتخاب کنید.</p>
    <p v-else-if="!folders.items.length" class="px-1 text-sm text-gray-400">پوشه‌ای یافت نشد.</p>

    <template v-else>
      <ul class="space-y-0.5">
        <FolderTreeItem
          v-for="folder in systemFolderRoots"
          :key="folder.id"
          :folder="folder"
          :active-id="folders.activeId"
          :icons="FOLDER_ICONS"
          :deleting-id="deletingFolderId"
          @select="selectFolder"
          @drop-message="handleDropMessage"
          @delete="handleDeleteFolder"
        />
      </ul>

      <template v-if="userFolderRoots.length">
        <div class="my-2 flex items-center gap-2 px-1">
          <span class="h-px flex-1 bg-gray-200"></span>
          <span class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">پوشه‌های شما</span>
          <span class="h-px flex-1 bg-gray-200"></span>
        </div>

        <ul class="space-y-0.5">
          <FolderTreeItem
            v-for="folder in userFolderRoots"
            :key="folder.id"
            :folder="folder"
            :active-id="folders.activeId"
            :icons="FOLDER_ICONS"
            :deleting-id="deletingFolderId"
            @select="selectFolder"
            @drop-message="handleDropMessage"
            @delete="handleDeleteFolder"
          />
        </ul>
      </template>
    </template>

    <p v-if="deleteError" class="mt-1.5 px-1 text-xs text-red-600">
      {{ deleteError?.message ?? 'حذف پوشه با خطا مواجه شد.' }}
    </p>
  </div>
</template>
