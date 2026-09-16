<script setup>
import { watch, onMounted } from 'vue'
import { useMessagesStore } from '../stores/messages'
import { useFoldersStore } from '../stores/folders'
import LoadingSpinner from './LoadingSpinner.vue'
import ErrorBanner from './ErrorBanner.vue'
import EmptyState from './EmptyState.vue'

const props = defineProps({
  folderId: { type: Number, default: null },
})

const emit = defineEmits(['select'])

const messages = useMessagesStore()
const folders = useFoldersStore()

const dateFormatter = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'short', timeStyle: 'short' })
function formatDate(iso) {
  return dateFormatter.format(new Date(iso))
}

function loadList(page = 1) {
  if (!props.folderId) {
    messages.reset()
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

function goToPage(page) {
  if (page >= 1 && page <= (messages.meta?.last_page ?? 1)) {
    loadList(page)
  }
}

watch(() => props.folderId, () => loadList(1))
onMounted(() => loadList(1))
</script>

<template>
  <div class="flex h-full flex-col">
    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
      <h2 class="text-sm font-semibold text-gray-800">پیام‌ها</h2>
      <button
        type="button"
        :disabled="!folderId || messages.syncing"
        class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
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

    <ErrorBanner
      v-if="messages.syncError"
      class="mx-4 mt-3"
      :message="messages.syncError?.message ?? 'دریافت ایمیل‌های جدید با خطا مواجه شد.'"
      @retry="syncNow"
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
        v-else-if="!folderId"
        title="یک پوشه انتخاب کنید"
        description="برای مشاهده‌ی پیام‌ها، از منوی سمت راست یک پوشه انتخاب کنید."
      />
      <EmptyState
        v-else-if="!messages.items.length"
        title="این پوشه خالی است"
        description="با دکمه‌ی «دریافت ایمیل جدید» بررسی کنید."
      />

      <ul v-else class="divide-y divide-gray-100">
        <li v-for="message in messages.items" :key="message.id">
          <button
            type="button"
            class="flex w-full flex-col gap-1 px-4 py-3 text-right transition hover:bg-gray-50"
            :class="{ 'bg-indigo-50/60': messages.selected?.id === message.id }"
            @click="emit('select', message.id)"
          >
            <div class="flex items-center gap-2">
              <span
                v-if="!message.is_read"
                class="h-2 w-2 shrink-0 rounded-full bg-indigo-600"
                aria-hidden="true"
              />
              <bdi
                dir="ltr"
                class="truncate text-sm"
                :class="message.is_read ? 'text-gray-600' : 'font-semibold text-gray-900'"
              >
                {{ message.from.name || message.from.email }}
              </bdi>
              <span class="mr-auto shrink-0 text-xs text-gray-400">{{ formatDate(message.date) }}</span>
            </div>

            <div class="flex items-center gap-1.5 pr-4">
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

            <div class="flex items-center justify-between pr-4">
              <p class="truncate text-xs text-gray-400">{{ message.preview }}</p>
              <button
                type="button"
                class="shrink-0 rounded p-0.5 hover:bg-gray-200"
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
