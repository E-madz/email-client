<script setup>
import { ref, computed } from 'vue'
import { useMessagesStore } from '../stores/messages'
import { useFoldersStore } from '../stores/folders'
import { useAccountsStore } from '../stores/accounts'
import { useComposeStore } from '../stores/compose'
import { api, ApiError } from '../lib/api'
import LoadingSpinner from './LoadingSpinner.vue'
import ErrorBanner from './ErrorBanner.vue'
import EmptyState from './EmptyState.vue'
import SafeHtmlFrame from './SafeHtmlFrame.vue'

const messages = useMessagesStore()
const folders = useFoldersStore()
const accounts = useAccountsStore()
const compose = useComposeStore()

const isTrash = computed(() => folders.active?.type === 'trash')

function reply() {
  if (messages.selected) compose.openReply(accounts.activeId, messages.selected)
}

function forward() {
  if (messages.selected) compose.openForward(accounts.activeId, messages.selected)
}

function deleteMessage() {
  const message = messages.selected
  if (!message) return

  if (isTrash.value && !confirm('این پیام برای همیشه حذف شود؟ این کار قابل بازگشت نیست.')) {
    return
  }

  messages.deleteMessage(message.id)
}

// Sent/Drafts only ever hold what the system itself put there — moving an
// arbitrary message into either would misrepresent it, so the backend
// rejects it too (§8 item 66). A message the account sent also can't
// retroactively become something it received, so Inbox/Spam are excluded
// too when the selected message is itself Sent/Drafts (§8 item 68).
const UNMOVABLE_TARGET_TYPES = ['sent', 'drafts']
const OUTBOUND_TYPES = ['sent', 'drafts']
const INBOUND_TYPES = ['inbox', 'spam']
const moveTargets = computed(() => {
  const sourceFolder = folders.items.find((item) => item.id === messages.selected?.folder_id)
  const alsoExcluded = OUTBOUND_TYPES.includes(sourceFolder?.type) ? INBOUND_TYPES : []

  return folders.items.filter(
    (folder) =>
      folder.id !== messages.selected?.folder_id &&
      !UNMOVABLE_TARGET_TYPES.includes(folder.type) &&
      !alsoExcluded.includes(folder.type),
  )
})
// A draft has its own lifecycle (edit/send/delete) — it can't be moved to an
// arbitrary folder via this generic action. In practice a draft is opened in
// the composer, not here, but a starred draft could still surface in this
// reader via the cross-folder Starred view, so this stays defense-in-depth.
const isMovable = computed(() => {
  const folder = folders.items.find((item) => item.id === messages.selected?.folder_id)
  return folder?.type !== 'drafts'
})

function handleMoveSelect(event) {
  const folderId = Number(event.target.value)
  event.target.value = ''
  if (folderId && messages.selected) messages.moveMessage(messages.selected.id, folderId)
}

const downloadingId = ref(null)
const downloadError = ref(null)

async function downloadAttachment(attachment) {
  downloadingId.value = attachment.id
  downloadError.value = null

  try {
    const blob = await api.downloadBlob(`/attachments/${attachment.id}`)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = attachment.filename
    link.click()
    URL.revokeObjectURL(url)
  } catch (error) {
    downloadError.value = error instanceof ApiError ? error.message : 'دانلود پیوست با خطا مواجه شد.'
  } finally {
    downloadingId.value = null
  }
}

const dateFormatter = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'full', timeStyle: 'short' })

function formatAddressList(list) {
  if (!list?.length) return '—'
  return list.map((address) => address.name || address.email).join('، ')
}

function toggleRead() {
  const message = messages.selected
  messages.setFlags(message.id, { is_read: !message.is_read })
}

function toggleFlag() {
  const message = messages.selected
  messages.setFlags(message.id, { is_flagged: !message.is_flagged })
}
</script>

<template>
  <div class="flex h-full flex-col">
    <LoadingSpinner v-if="messages.detailLoading" label="در حال بارگذاری پیام…" />

    <ErrorBanner
      v-else-if="messages.detailError"
      class="m-4"
      :message="messages.detailError?.message ?? 'بارگذاری پیام با خطا مواجه شد.'"
      @retry="() => messages.openMessage(messages.selected?.id)"
    />

    <EmptyState
      v-else-if="!messages.selected"
      title="پیامی انتخاب نشده"
      description="یک ایمیل را از لیست کنار انتخاب کنید تا محتوای آن را ببینید."
    >
      <template #icon>
        <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"
          />
        </svg>
      </template>
    </EmptyState>

    <template v-else>
      <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-5">
        <div class="min-w-0">
          <h1 class="mb-1.5 truncate text-xl font-bold tracking-tight text-gray-900">
            {{ messages.selected.subject || '(بدون موضوع)' }}
          </h1>
          <p class="text-sm text-gray-600">
            <bdi dir="ltr" class="font-medium">
              {{ messages.selected.from.name || messages.selected.from.email }}
            </bdi>
            <span v-if="messages.selected.from.name" dir="ltr" class="text-gray-400">
              ({{ messages.selected.from.email }})
            </span>
          </p>
          <p class="mt-0.5 text-xs text-gray-400">{{ dateFormatter.format(new Date(messages.selected.date)) }}</p>
          <p class="mt-1 text-xs text-gray-400">به: {{ formatAddressList(messages.selected.to) }}</p>
          <p v-if="messages.selected.cc?.length" class="text-xs text-gray-400">
            رونوشت: {{ formatAddressList(messages.selected.cc) }}
          </p>
        </div>

        <div class="flex shrink-0 items-center gap-1">
          <button
            type="button"
            class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100"
            @click="reply"
          >
            پاسخ
          </button>
          <button
            type="button"
            class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100"
            @click="forward"
          >
            ارجاع
          </button>
          <button
            type="button"
            class="rounded-lg p-2 text-gray-400 transition hover:bg-amber-50 hover:text-amber-500"
            :disabled="messages.flagUpdatingId === messages.selected.id"
            :aria-label="messages.selected.is_flagged ? 'حذف ستاره' : 'ستاره‌دار کردن'"
            @click="toggleFlag"
          >
            <svg
              class="h-5 w-5"
              :class="messages.selected.is_flagged ? 'fill-amber-400 text-amber-400' : 'fill-none text-gray-300'"
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
            class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100"
            :disabled="messages.flagUpdatingId === messages.selected.id"
            @click="toggleRead"
          >
            {{ messages.selected.is_read ? 'علامت‌گذاری به‌عنوان خوانده‌نشده' : 'علامت‌گذاری به‌عنوان خوانده‌شده' }}
          </button>

          <button
            type="button"
            :disabled="messages.deletingId === messages.selected.id"
            class="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-500 disabled:cursor-wait"
            :aria-label="isTrash ? 'حذف برای همیشه' : 'انتقال به سطل زباله'"
            @click="deleteMessage"
          >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="m14.74 9-.346 9M9.606 9l.346 9M4.772 5.79A2.25 2.25 0 0 1 6.999 4h9.998a2.25 2.25 0 0 1 2.227 1.79M4.772 5.79 4 5.018M4.772 5.79 6.09 21.243A2.25 2.25 0 0 0 8.334 23h7.332a2.25 2.25 0 0 0 2.244-2.007L19 5.79M19.228 5.79H4.772"
              />
            </svg>
          </button>

          <div v-if="isMovable" class="relative">
            <select
              class="absolute inset-0 h-9 w-9 cursor-pointer appearance-none opacity-0"
              :disabled="messages.movingId === messages.selected.id"
              aria-label="انتقال به پوشه"
              @change="handleMoveSelect"
            >
              <option value="">انتقال به…</option>
              <option v-for="folder in moveTargets" :key="folder.id" :value="folder.id">
                {{ folder.display_name }}
              </option>
            </select>
            <span class="pointer-events-none flex rounded-lg p-2 text-gray-400 transition hover:bg-indigo-50 hover:text-indigo-500">
              <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M8.25 9.75h7.5M8.25 9.75a2.25 2.25 0 0 1-2.25-2.25V6a2.25 2.25 0 0 1 2.25-2.25h4.5L17.25 6v1.5a2.25 2.25 0 0 1-2.25 2.25M8.25 9.75v10.5a2.25 2.25 0 0 0 2.25 2.25h3a2.25 2.25 0 0 0 2.25-2.25V9.75"
                />
              </svg>
            </span>
          </div>

          <button
            type="button"
            class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
            aria-label="بستن"
            @click="messages.closeMessage()"
          >
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path
                d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
              />
            </svg>
          </button>
        </div>
      </div>

      <div v-if="messages.selected.attachments?.length" class="border-b border-gray-100 px-6 py-3">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="attachment in messages.selected.attachments"
            :key="attachment.id"
            type="button"
            :disabled="downloadingId === attachment.id"
            class="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 disabled:cursor-wait disabled:opacity-60"
            @click="downloadAttachment(attachment)"
          >
            <svg
              v-if="downloadingId !== attachment.id"
              class="h-3.5 w-3.5 text-gray-400"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"
              />
            </svg>
            <svg v-else class="h-3.5 w-3.5 animate-spin text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"
              />
            </svg>
            <span dir="ltr">{{ attachment.filename }}</span>
          </button>
        </div>
        <p v-if="downloadError" class="mt-1.5 text-xs text-red-600">{{ downloadError }}</p>
      </div>

      <div class="thin-scrollbar flex-1 overflow-y-auto">
        <SafeHtmlFrame v-if="messages.selected.body_html" :html="messages.selected.body_html" />
        <p v-else class="whitespace-pre-wrap px-6 py-4 text-sm text-gray-700">
          {{ messages.selected.body_text || 'این پیام محتوایی ندارد.' }}
        </p>
      </div>
    </template>
  </div>
</template>
