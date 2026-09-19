<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useAccountsStore } from '../stores/accounts'
import { useFoldersStore } from '../stores/folders'
import { useMessagesStore } from '../stores/messages'
import { useComposeStore } from '../stores/compose'
import { api } from '../lib/api'
import AccountSwitcher from '../components/AccountSwitcher.vue'
import FolderList from '../components/FolderList.vue'
import MessageList from '../components/MessageList.vue'
import MessageReader from '../components/MessageReader.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'
import ComposeModal from '../components/ComposeModal.vue'

const auth = useAuthStore()
const accounts = useAccountsStore()
const folders = useFoldersStore()
const messages = useMessagesStore()
const compose = useComposeStore()

const activeFolderId = ref(null)

const activeAccountId = computed(() => accounts.activeId)

onMounted(() => accounts.fetch())

function handleAccountChange() {
  activeFolderId.value = null
  folders.reset()
  messages.reset()
}

function handleFolderSelect(folderId) {
  activeFolderId.value = folderId
}

async function openMessage(messageId) {
  const activeFolder = folders.items.find((folder) => folder.id === activeFolderId.value)

  // A draft isn't read, it's edited — open it straight into the composer
  // instead of the read-only reader (CLAUDE.md §8, Phase 4b).
  if (activeFolder?.type === 'drafts') {
    const full = await api.get(`/messages/${messageId}`)
    compose.openDraft(activeAccountId.value, full)
    return
  }

  messages.openMessage(messageId)
}

watch(activeFolderId, () => messages.closeMessage())

async function handleLogout() {
  await auth.logout()
}
</script>

<template>
  <div class="flex h-screen flex-col bg-gray-50">
    <header class="flex h-16 shrink-0 items-center justify-between border-b border-gray-200/80 bg-white/80 px-5 backdrop-blur-sm">
      <div class="flex items-center gap-2.5">
        <div class="brand-gradient flex h-9 w-9 items-center justify-center rounded-xl text-white shadow-md shadow-indigo-500/25">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"
            />
          </svg>
        </div>
        <span class="text-[15px] font-bold tracking-tight text-gray-900">ایمیل من</span>
      </div>

      <div class="flex items-center gap-3">
        <button
          type="button"
          :disabled="!activeAccountId"
          class="brand-gradient flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-semibold text-white shadow-md shadow-indigo-500/25 transition hover:shadow-indigo-500/40 hover:brightness-110 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:brightness-100"
          @click="compose.open(activeAccountId)"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
          نوشتن ایمیل
        </button>
        <div class="mx-1 h-6 w-px bg-gray-200" />
        <RouterLink
          :to="{ name: 'profile' }"
          class="rounded-xl px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900"
        >
          {{ auth.user?.name }}
        </RouterLink>
        <button
          type="button"
          class="rounded-xl px-3 py-2 text-sm font-medium text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
          @click="handleLogout"
        >
          خروج
        </button>
      </div>
    </header>

    <LoadingSpinner v-if="accounts.loading && !accounts.items.length" label="در حال بارگذاری اکانت‌ها…" />

    <div v-else class="flex min-h-0 flex-1">
      <aside class="thin-scrollbar w-64 shrink-0 overflow-y-auto border-l border-gray-200/80 bg-white p-3">
        <AccountSwitcher @change="handleAccountChange" />
        <div class="my-3 border-t border-gray-100" />
        <FolderList :account-id="activeAccountId" @select="handleFolderSelect" />
      </aside>

      <section class="w-96 shrink-0 border-l border-gray-200/80 bg-white">
        <MessageList :folder-id="activeFolderId" :account-id="activeAccountId" @select="openMessage" />
      </section>

      <section class="min-w-0 flex-1 bg-white">
        <MessageReader />
      </section>
    </div>

    <ComposeModal />
  </div>
</template>
