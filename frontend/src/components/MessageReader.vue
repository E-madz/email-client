<script setup>
import { useMessagesStore } from '../stores/messages'
import LoadingSpinner from './LoadingSpinner.vue'
import ErrorBanner from './ErrorBanner.vue'
import EmptyState from './EmptyState.vue'
import SafeHtmlFrame from './SafeHtmlFrame.vue'

const messages = useMessagesStore()

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
      <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-6 py-4">
        <div class="min-w-0">
          <h1 class="mb-1 truncate text-lg font-bold text-gray-900">
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
            class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
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
            class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-500 hover:bg-gray-100"
            :disabled="messages.flagUpdatingId === messages.selected.id"
            @click="toggleRead"
          >
            {{ messages.selected.is_read ? 'علامت‌گذاری به‌عنوان خوانده‌نشده' : 'علامت‌گذاری به‌عنوان خوانده‌شده' }}
          </button>

          <button
            type="button"
            class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
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

      <div v-if="messages.selected.attachments?.length" class="flex flex-wrap gap-2 border-b border-gray-100 px-6 py-3">
        <span
          v-for="attachment in messages.selected.attachments"
          :key="attachment.id"
          class="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs text-gray-600"
        >
          <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M18.375 12.739 12 19.114a4.688 4.688 0 0 1-6.629-6.629l7.5-7.5a3.125 3.125 0 0 1 4.419 4.419l-7.5 7.5a1.563 1.563 0 0 1-2.212-2.212l6.75-6.75"
            />
          </svg>
          {{ attachment.filename }}
        </span>
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
