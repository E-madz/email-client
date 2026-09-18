<script setup>
import { ref, watch, onMounted, computed } from 'vue'
import { useMessagesStore, SEARCH_ID } from '../stores/messages'
import { useFoldersStore, STARRED_ID } from '../stores/folders'
import LoadingSpinner from './LoadingSpinner.vue'
import ErrorBanner from './ErrorBanner.vue'
import EmptyState from './EmptyState.vue'

const props = defineProps({
  folderId: { type: [Number, String], default: null },
  accountId: { type: Number, default: null },
})

const emit = defineEmits(['select'])

const messages = useMessagesStore()
const folders = useFoldersStore()

const isStarredView = computed(() => props.folderId === STARRED_ID)
const isTrash = computed(() => folders.active?.type === 'trash')

const searchTerm = ref('')
const isSearchActive = computed(() => searchTerm.value.trim() !== '')
// Both Starred and Search are cross-folder views — a message shown there can
// belong to any real folder (including Trash), so per-message context (the
// delete label, permanent-vs-move-to-trash behavior) must check that
// message's *own* folder rather than the sidebar's currently active one.
const isCrossFolderView = computed(() => isStarredView.value || isSearchActive.value)
let searchDebounceTimer = null

watch(searchTerm, (value) => {
  clearTimeout(searchDebounceTimer)
  const trimmed = value.trim()

  if (!trimmed) {
    loadList(1)
    return
  }

  searchDebounceTimer = setTimeout(() => {
    if (props.accountId) messages.search(props.accountId, trimmed, 1)
  }, 300)
})

function clearSearch() {
  searchTerm.value = ''
}

function folderTypeOf(folderId) {
  return folders.items.find((item) => item.id === folderId)?.type
}

/**
 * In Sent/Drafts the sender is always the account's own address — showing
 * it is redundant and less useful than who the message actually went to
 * (§8 item 66), matching every mainstream webmail client's list view.
 */
function isOutgoingFolder(message) {
  const type = folderTypeOf(message.folder_id ?? props.folderId)
  return type === 'sent' || type === 'drafts'
}

function displayIdentity(message) {
  if (!isOutgoingFolder(message)) {
    return message.from.name || message.from.email
  }

  if (!message.to?.length) {
    return 'بدون گیرنده'
  }

  return `به: ${message.to.map((recipient) => recipient.name || recipient.email).join('، ')}`
}

const dateFormatter = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'short', timeStyle: 'short' })
function formatDate(iso) {
  return dateFormatter.format(new Date(iso))
}

function loadList(page = 1) {
  if (isSearchActive.value) {
    if (props.accountId) messages.search(props.accountId, searchTerm.value.trim(), page)
    return
  }

  if (!props.folderId) {
    messages.reset()
    return
  }

  if (isStarredView.value) {
    messages.fetchStarred(props.accountId, page)
    return
  }

  // A folder that's never been synced would otherwise always show empty on
  // first visit — this app's sync is on-demand-per-folder (not background
  // polling), so trigger that first sync automatically instead of making
  // the user discover the "دریافت ایمیل جدید" button themselves.
  const folder = folders.items.find((item) => item.id === props.folderId)
  if (page === 1 && folder && !folder.last_synced_at) {
    messages.sync(props.folderId)
    return
  }

  messages.fetchList(props.folderId, page)
}

async function syncNow() {
  if (props.folderId) {
    await messages.sync(props.folderId)
  }
}

function toggleFlag(event, message) {
  event.stopPropagation()
  messages.setFlags(message.id, { is_flagged: !message.is_flagged })
}

function deleteMessage(event, message) {
  event.stopPropagation()

  // In a cross-folder view (Starred/Search) a message can belong to any
  // real folder — check its own folder's type rather than the (virtual)
  // active folder.
  const inTrash = isCrossFolderView.value ? folderTypeOf(message.folder_id) === 'trash' : isTrash.value

  if (inTrash && !confirm('این پیام برای همیشه حذف شود؟ این کار قابل بازگشت نیست.')) {
    return
  }

  messages.deleteMessage(message.id)
}

// Sent/Drafts only ever hold what the system itself put there (an actual
// sent message, a saved draft) — moving an arbitrary message into either
// would misrepresent it, so the backend rejects it too (§8 item 66).
const UNMOVABLE_TARGET_TYPES = ['sent', 'drafts']
// A message the account sent (`sent`/`drafts`) can't retroactively become
// something it received (`inbox`/`spam`) — the backend enforces this
// symmetrically in both directions (§8 item 68); the frontend only needs
// the outbound-source case here since inbound targets are the only ones
// that aren't already excluded by UNMOVABLE_TARGET_TYPES above.
const OUTBOUND_TYPES = ['sent', 'drafts']
const INBOUND_TYPES = ['inbox', 'spam']

/**
 * Folders a message could be moved to — every real folder except the one
 * it's currently in, Sent/Drafts, and (for a message the account itself
 * sent) Inbox/Spam. Starred is never a candidate since it's a virtual view,
 * not a real folder (it's not part of `folders.items` at all).
 */
function moveTargets(message) {
  const sourceType = folderTypeOf(message.folder_id ?? props.folderId)
  const alsoExcluded = OUTBOUND_TYPES.includes(sourceType) ? INBOUND_TYPES : []

  return folders.items.filter(
    (folder) =>
      folder.id !== (message.folder_id ?? props.folderId) &&
      !UNMOVABLE_TARGET_TYPES.includes(folder.type) &&
      !alsoExcluded.includes(folder.type),
  )
}

/**
 * A draft has its own lifecycle (edit/send/delete) — it can't be moved to
 * an arbitrary folder via this generic action, so neither the "move to"
 * menu nor drag-and-drop is offered for a message sitting in Drafts.
 */
function isMovable(message) {
  return folderTypeOf(message.folder_id ?? props.folderId) !== 'drafts'
}

function handleMoveSelect(event, message) {
  const folderId = Number(event.target.value)
  event.target.value = ''
  if (folderId) messages.moveMessage(message.id, folderId)
}

function handleDragStart(event, message) {
  event.dataTransfer.setData('text/plain', String(message.id))
  event.dataTransfer.effectAllowed = 'move'
}

function emptyTrash() {
  if (!props.folderId) return
  if (!confirm('همه‌ی پیام‌های سطل زباله برای همیشه حذف شوند؟ این کار قابل بازگشت نیست.')) return

  messages.emptyTrash(props.folderId)
}

function deleteLabel(message) {
  const inTrash = isCrossFolderView.value ? folderTypeOf(message.folder_id) === 'trash' : isTrash.value
  return inTrash ? 'حذف برای همیشه' : 'انتقال به سطل زباله'
}

function goToPage(page) {
  if (page >= 1 && page <= (messages.meta?.last_page ?? 1)) {
    loadList(page)
  }
}

watch(
  () => props.folderId,
  () => {
    // Selecting a folder from the sidebar exits search mode, same as every
    // mainstream webmail client. Clearing searchTerm alone already triggers
    // its own watcher's reload, so only call loadList directly when there
    // was no active search to avoid firing the request twice.
    if (searchTerm.value) {
      searchTerm.value = ''
    } else {
      loadList(1)
    }
  },
)
watch(
  () => props.accountId,
  () => {
    searchTerm.value = ''
    if (isStarredView.value) loadList(1)
  },
)
onMounted(() => loadList(1))
</script>

<template>
  <div class="flex h-full flex-col">
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3.5">
      <h2 class="text-[15px] font-bold tracking-tight text-gray-900">پیام‌ها</h2>
      <div class="flex items-center gap-2">
        <button
          v-if="isTrash && !isSearchActive && messages.items.length"
          type="button"
          :disabled="messages.emptyingTrash"
          class="flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50/50 px-2.5 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
          @click="emptyTrash"
        >
          {{ messages.emptyingTrash ? 'در حال خالی کردن…' : 'خالی کردن سطل زباله' }}
        </button>
        <button
          v-if="!isStarredView && !isSearchActive"
          type="button"
          :disabled="!folderId || messages.syncing"
          class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-600 transition hover:border-gray-300 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
          @click="syncNow"
        >
          <svg
            class="h-3.5 w-3.5"
            :class="{ 'animate-spin': messages.syncing }"
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
          {{ messages.syncing ? 'در حال دریافت…' : 'دریافت ایمیل جدید' }}
        </button>
      </div>
    </div>

    <div class="border-b border-gray-100 px-4 py-2.5">
      <div class="relative">
        <svg
          class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.75"
          aria-hidden="true"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M18 10.5a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z" />
        </svg>
        <input
          v-model="searchTerm"
          type="text"
          placeholder="جستجو در موضوع، فرستنده، گیرنده و متن پیام…"
          :disabled="!accountId"
          class="w-full rounded-xl border border-gray-200 bg-gray-50/60 py-2 pl-9 pr-9 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10 disabled:cursor-not-allowed disabled:bg-gray-50"
        />
        <button
          v-if="searchTerm"
          type="button"
          class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
          aria-label="پاک کردن جستجو"
          @click="clearSearch"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
              d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
            />
          </svg>
        </button>
      </div>
    </div>

    <ErrorBanner
      v-if="messages.syncError"
      class="mx-4 mt-3"
      :message="messages.syncError?.message ?? 'دریافت ایمیل‌های جدید با خطا مواجه شد.'"
      @retry="syncNow"
    />
    <ErrorBanner
      v-if="messages.emptyTrashError"
      class="mx-4 mt-3"
      :message="messages.emptyTrashError?.message ?? 'خالی کردن سطل زباله با خطا مواجه شد.'"
      :show-retry="false"
    />
    <ErrorBanner
      v-if="messages.deleteError"
      class="mx-4 mt-3"
      :message="messages.deleteError?.message ?? 'حذف پیام با خطا مواجه شد.'"
      :show-retry="false"
    />
    <ErrorBanner
      v-if="messages.moveError"
      class="mx-4 mt-3"
      :message="messages.moveError?.message ?? 'انتقال پیام با خطا مواجه شد.'"
      :show-retry="false"
    />

    <div class="thin-scrollbar flex-1 overflow-y-auto">
      <LoadingSpinner v-if="messages.listLoading && !messages.items.length" label="در حال بارگذاری پیام‌ها…" />
      <ErrorBanner
        v-else-if="messages.listError"
        class="m-4"
        message="دریافت پیام‌ها با خطا مواجه شد."
        @retry="() => loadList(1)"
      />
      <EmptyState
        v-else-if="isSearchActive && !messages.items.length"
        title="نتیجه‌ای یافت نشد"
        description="عبارت دیگری را امتحان کنید."
      />
      <EmptyState
        v-else-if="!isSearchActive && !folderId"
        title="یک پوشه انتخاب کنید"
        description="برای مشاهده‌ی پیام‌ها، از منوی سمت راست یک پوشه انتخاب کنید."
      />
      <EmptyState
        v-else-if="!messages.items.length && isStarredView"
        title="پیام ستاره‌داری وجود ندارد"
        description="پیام‌هایی که ستاره‌دار می‌کنید این‌جا نمایش داده می‌شوند."
      />
      <EmptyState
        v-else-if="!messages.items.length"
        title="این پوشه خالی است"
        description="با دکمه‌ی «دریافت ایمیل جدید» بررسی کنید."
      />

      <ul v-else class="divide-y divide-gray-100">
        <li v-for="message in messages.items" :key="message.id" class="relative">
          <span
            v-if="!message.is_read"
            class="brand-gradient absolute inset-y-0 right-0 w-[3px]"
            aria-hidden="true"
          />
          <button
            type="button"
            :draggable="isMovable(message)"
            class="flex w-full flex-col gap-1 px-4 py-3.5 text-right transition hover:bg-gray-50/80"
            :class="{ 'bg-indigo-50/70': messages.selected?.id === message.id }"
            @click="emit('select', message.id)"
            @dragstart="isMovable(message) && handleDragStart($event, message)"
          >
            <div class="flex items-center gap-2">
              <bdi
                :dir="isOutgoingFolder(message) ? 'auto' : 'ltr'"
                class="truncate text-sm"
                :class="message.is_read ? 'text-gray-600' : 'font-semibold text-gray-900'"
              >
                {{ displayIdentity(message) }}
              </bdi>
              <span class="mr-auto shrink-0 text-xs text-gray-400">{{ formatDate(message.date) }}</span>
            </div>

            <div class="flex items-center gap-1.5">
              <p class="truncate text-sm" :class="message.is_read ? 'text-gray-600' : 'font-medium text-gray-800'">
                {{ message.subject || '(بدون موضوع)' }}
              </p>
              <svg
                v-if="message.has_attachments"
                class="h-3.5 w-3.5 shrink-0 text-gray-400"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.75"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M18.375 12.739 12 19.114a4.688 4.688 0 0 1-6.629-6.629l7.5-7.5a3.125 3.125 0 0 1 4.419 4.419l-7.5 7.5a1.563 1.563 0 0 1-2.212-2.212l6.75-6.75"
                />
              </svg>
            </div>

            <div class="flex items-center justify-between">
              <p class="truncate text-xs text-gray-400">{{ message.preview }}</p>
              <div class="flex shrink-0 items-center gap-1">
                <button
                  type="button"
                  class="rounded-md p-1 transition hover:bg-amber-50"
                  :aria-label="message.is_flagged ? 'حذف ستاره' : 'ستاره‌دار کردن'"
                  @click="toggleFlag($event, message)"
                >
                  <svg
                    class="h-4 w-4"
                    :class="message.is_flagged ? 'fill-amber-400 text-amber-400' : 'fill-none text-gray-300'"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.5"
                    aria-hidden="true"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="m11.48 3.499 2.222 4.5 4.966.723-3.594 3.503.848 4.946L11.48 14.75l-4.442 2.421.848-4.946L4.29 8.722l4.966-.723 2.223-4.5Z"
                    />
                  </svg>
                </button>
                <button
                  type="button"
                  :disabled="messages.deletingId === message.id"
                  class="rounded-md p-1 text-gray-300 transition hover:bg-red-50 hover:text-red-500 disabled:cursor-wait"
                  :aria-label="deleteLabel(message)"
                  @click="deleteMessage($event, message)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="m14.74 9-.346 9M9.606 9l.346 9M4.772 5.79A2.25 2.25 0 0 1 6.999 4h9.998a2.25 2.25 0 0 1 2.227 1.79M4.772 5.79 4 5.018M4.772 5.79 6.09 21.243A2.25 2.25 0 0 0 8.334 23h7.332a2.25 2.25 0 0 0 2.244-2.007L19 5.79M19.228 5.79H4.772"
                    />
                  </svg>
                </button>
                <div v-if="isMovable(message)" class="relative" @click.stop>
                  <select
                    class="absolute inset-0 h-4 w-4 cursor-pointer appearance-none opacity-0"
                    :disabled="messages.movingId === message.id"
                    aria-label="انتقال به پوشه"
                    @change="handleMoveSelect($event, message)"
                  >
                    <option value="">انتقال به…</option>
                    <option v-for="folder in moveTargets(message)" :key="folder.id" :value="folder.id">
                      {{ folder.display_name }}
                    </option>
                  </select>
                  <span class="pointer-events-none flex rounded-md p-1 text-gray-300 transition hover:bg-indigo-50 hover:text-indigo-500">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M8.25 9.75h7.5M8.25 9.75a2.25 2.25 0 0 1-2.25-2.25V6a2.25 2.25 0 0 1 2.25-2.25h4.5L17.25 6v1.5a2.25 2.25 0 0 1-2.25 2.25M8.25 9.75v10.5a2.25 2.25 0 0 0 2.25 2.25h3a2.25 2.25 0 0 0 2.25-2.25V9.75"
                      />
                    </svg>
                  </span>
                </div>
              </div>
            </div>
          </button>
        </li>
      </ul>
    </div>

    <div
      v-if="messages.meta && messages.meta.last_page > 1"
      class="flex items-center justify-between border-t border-gray-200 px-4 py-2 text-xs text-gray-500"
    >
      <button
        type="button"
        class="rounded px-2 py-1 hover:bg-gray-100 disabled:opacity-40"
        :disabled="messages.meta.current_page <= 1"
        @click="goToPage(messages.meta.current_page - 1)"
      >
        قبلی
      </button>
      <span>صفحه {{ messages.meta.current_page }} از {{ messages.meta.last_page }}</span>
      <button
        type="button"
        class="rounded px-2 py-1 hover:bg-gray-100 disabled:opacity-40"
        :disabled="messages.meta.current_page >= messages.meta.last_page"
        @click="goToPage(messages.meta.current_page + 1)"
      >
        بعدی
      </button>
    </div>
  </div>
</template>
