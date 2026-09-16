<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useAccountsStore } from '../stores/accounts'
import { useMessagesStore } from '../stores/messages'
import AccountSwitcher from '../components/AccountSwitcher.vue'
import FolderList from '../components/FolderList.vue'
import MessageList from '../components/MessageList.vue'
import MessageReader from '../components/MessageReader.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'

const auth = useAuthStore()
const accounts = useAccountsStore()
const messages = useMessagesStore()

const activeFolderId = ref(null)

const activeAccountId = computed(() => accounts.activeId)

onMounted(() => accounts.fetch())

function handleAccountChange() {
  activeFolderId.value = null
  messages.reset()
}

function handleFolderSelect(folderId) {
  activeFolderId.value = folderId
}

function openMessage(messageId) {
  messages.openMessage(messageId)
}

watch(activeFolderId, () => messages.closeMessage())

async function handleLogout() {
  await auth.logout()
}
</script>

<template>
  <div class="flex h-screen flex-col bg-gray-50">
    <header class="flex h-14 shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4">
      <div class="flex items-center gap-2">
        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white">
          <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"
            />
          </svg>
        </div>
        <span class="font-bold text-gray-900">ایمیل من</span>
      </div>

      <div class="flex items-center gap-3">
        <span class="text-sm text-gray-500">{{ auth.user?.name }}</span>
        <button
          type="button"
          class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-500 hover:bg-gray-100"
          @click="handleLogout"
        >
          خروج
        </button>
      </div>
    </header>

    <LoadingSpinner v-if="accounts.loading && !accounts.items.length" label="در حال بارگذاری اکانت‌ها…" />

    <div v-else class="flex min-h-0 flex-1">
      <aside class="thin-scrollbar w-64 shrink-0 overflow-y-auto border-l border-gray-200 bg-white p-3">
        <AccountSwitcher @change="handleAccountChange" />
        <div class="my-3 border-t border-gray-100" />
        <FolderList :account-id="activeAccountId" @select="handleFolderSelect" />
      </aside>

      <section class="w-96 shrink-0 border-l border-gray-200 bg-white">
        <MessageList :folder-id="activeFolderId" @select="openMessage" />
      </section>

      <section class="min-w-0 flex-1 bg-white">
        <MessageReader />
      </section>
    </div>
  </div>
</template>
