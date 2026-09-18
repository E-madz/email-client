<script setup>
import { ref } from 'vue'
import { useComposeStore } from '../stores/compose'
import EmailTagInput from './EmailTagInput.vue'
import ErrorBanner from './ErrorBanner.vue'

const compose = useComposeStore()
const validationMessage = ref('')

function handleFileChange(event) {
  compose.addAttachments(Array.from(event.target.files ?? []))
  event.target.value = ''
}

function formatSize(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

async function handleSubmit() {
  if (compose.mode !== 'draft' && compose.draft.to.length === 0) {
    validationMessage.value = 'وارد کردن حداقل یک گیرنده الزامی است.'
    return
  }
  validationMessage.value = ''

  try {
    await compose.send()
  } catch {
    // compose.error already holds the message; nothing else to do here.
  }
}

async function handleSaveDraft() {
  try {
    await compose.saveDraft()
  } catch {
    // compose.error already holds the message; nothing else to do here.
  }
}

const titles = {
  new: 'نوشتن ایمیل جدید',
  reply: 'پاسخ به پیام',
  forward: 'ارجاع پیام',
  draft: 'ویرایش پیش‌نویس',
}
</script>

<template>
  <div
    v-if="compose.isOpen"
    class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4 backdrop-blur-[2px]"
    @click.self="compose.close"
  >
    <div class="flex max-h-[90vh] w-full max-w-xl flex-col rounded-3xl bg-white shadow-2xl shadow-black/20">
      <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
        <h2 class="text-[15px] font-bold tracking-tight text-gray-900">{{ titles[compose.mode] ?? titles.new }}</h2>
        <button
          type="button"
          class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
          aria-label="بستن"
          @click="compose.close"
        >
          <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
              d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
            />
          </svg>
        </button>
      </div>

      <form class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-5 py-4" @submit.prevent="handleSubmit">
        <ErrorBanner
          v-if="compose.error || validationMessage"
          :message="validationMessage || compose.error?.message || 'ارسال ایمیل با خطا مواجه شد.'"
          :show-retry="false"
        />

        <label class="block">
          <span class="mb-1.5 block text-xs font-semibold text-gray-500">گیرنده</span>
          <EmailTagInput v-model="compose.draft.to" placeholder="یک آدرس ایمیل بنویسید و Enter بزنید" />
        </label>

        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1.5 block text-xs font-semibold text-gray-500">رونوشت (CC)</span>
            <EmailTagInput v-model="compose.draft.cc" placeholder="یک آدرس ایمیل بنویسید و Enter بزنید" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-xs font-semibold text-gray-500">رونوشت مخفی (BCC)</span>
            <EmailTagInput v-model="compose.draft.bcc" placeholder="یک آدرس ایمیل بنویسید و Enter بزنید" />
          </label>
        </div>

        <label class="block">
          <span class="mb-1.5 block text-xs font-semibold text-gray-500">موضوع</span>
          <input
            v-model="compose.draft.subject"
            type="text"
            :required="compose.mode !== 'draft'"
            class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
          />
        </label>

        <label class="block flex-1">
          <span class="mb-1.5 block text-xs font-semibold text-gray-500">متن پیام</span>
          <textarea
            v-model="compose.draft.bodyText"
            :required="compose.mode !== 'draft'"
            rows="8"
            class="w-full resize-none rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
          />
        </label>

        <div>
          <label
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-600 transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M18.375 12.739 12 19.114a4.688 4.688 0 0 1-6.629-6.629l7.5-7.5a3.125 3.125 0 0 1 4.419 4.419l-7.5 7.5a1.563 1.563 0 0 1-2.212-2.212l6.75-6.75"
              />
            </svg>
            افزودن پیوست
            <input type="file" multiple class="hidden" @change="handleFileChange" />
          </label>

          <ul v-if="compose.existingAttachments.length" class="mt-2 space-y-1">
            <li
              v-for="attachment in compose.existingAttachments"
              :key="`existing-${attachment.id}`"
              class="flex items-center justify-between rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs text-gray-600"
            >
              <span class="truncate" dir="ltr">{{ attachment.filename }}</span>
              <div class="flex shrink-0 items-center gap-2">
                <span class="text-gray-400">{{ formatSize(attachment.size_bytes) }}</span>
                <button
                  type="button"
                  class="rounded-md p-1 text-gray-400 transition hover:bg-red-50 hover:text-red-500"
                  aria-label="حذف پیوست"
                  @click="compose.removeExistingAttachment(attachment.id)"
                >
                  <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                      d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
                    />
                  </svg>
                </button>
              </div>
            </li>
          </ul>

          <ul v-if="compose.draft.attachments.length" class="mt-2 space-y-1">
            <li
              v-for="(file, index) in compose.draft.attachments"
              :key="`${file.name}-${index}`"
              class="flex items-center justify-between rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs text-gray-600"
            >
              <span class="truncate" dir="ltr">{{ file.name }}</span>
              <div class="flex shrink-0 items-center gap-2">
                <span class="text-gray-400">{{ formatSize(file.size) }}</span>
                <button
                  type="button"
                  class="rounded-md p-1 text-gray-400 transition hover:bg-red-50 hover:text-red-500"
                  aria-label="حذف پیوست"
                  @click="compose.removeAttachment(index)"
                >
                  <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                      d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
                    />
                  </svg>
                </button>
              </div>
            </li>
          </ul>
        </div>

        <div class="mt-auto flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
          <button
            type="button"
            class="rounded-xl px-3.5 py-2 text-sm font-medium text-gray-500 transition hover:bg-gray-100"
            @click="compose.close"
          >
            انصراف
          </button>
          <button
            type="button"
            :disabled="compose.savingDraft || compose.sending"
            class="rounded-xl border border-gray-200 px-3.5 py-2 text-sm font-semibold text-gray-600 transition hover:border-gray-300 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
            @click="handleSaveDraft"
          >
            {{ compose.savingDraft ? 'در حال ذخیره…' : 'ذخیره پیش‌نویس' }}
          </button>
          <button
            type="submit"
            :disabled="compose.sending || compose.savingDraft"
            class="brand-gradient rounded-xl px-5 py-2 text-sm font-semibold text-white shadow-md shadow-indigo-500/25 transition hover:shadow-indigo-500/40 hover:brightness-110 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:brightness-100"
          >
            {{ compose.sending ? 'در حال ارسال…' : 'ارسال' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
