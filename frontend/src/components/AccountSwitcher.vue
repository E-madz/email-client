<script setup>
import { ref } from 'vue'
import { useAccountsStore } from '../stores/accounts'
import AddAccountModal from './AddAccountModal.vue'
import ReauthModal from './ReauthModal.vue'

const emit = defineEmits(['change'])

const accounts = useAccountsStore()
const showAddModal = ref(false)
const reauthTarget = ref(null)
const confirmingDeleteId = ref(null)

function select(id) {
  accounts.setActive(id)
  emit('change', id)
}

async function confirmDelete(id) {
  if (confirmingDeleteId.value !== id) {
    confirmingDeleteId.value = id
    return
  }

  await accounts.remove(id)
  confirmingDeleteId.value = null
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between px-1 pb-2">
      <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">اکانت‌های ایمیل</h2>
      <button
        type="button"
        class="rounded-full p-1 text-indigo-600 hover:bg-indigo-50"
        aria-label="افزودن اکانت"
        @click="showAddModal = true"
      >
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
        </svg>
      </button>
    </div>

    <ul v-if="accounts.items.length" class="space-y-1">
      <li v-for="account in accounts.items" :key="account.id">
        <div
          class="group flex items-center gap-2 rounded-lg px-2 py-2 text-sm transition"
          :class="account.id === accounts.activeId ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-100'"
        >
          <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-right" @click="select(account.id)">
            <span
              class="h-2 w-2 shrink-0 rounded-full"
              :class="account.status === 'active' ? 'bg-emerald-500' : 'bg-amber-500'"
            />
            <bdi dir="ltr" class="truncate">{{ account.email }}</bdi>
          </button>

          <button
            type="button"
            class="shrink-0 rounded p-1 text-gray-400 opacity-0 hover:bg-gray-200 hover:text-gray-600 group-hover:opacity-100"
            :class="{ 'text-red-600 opacity-100': confirmingDeleteId === account.id }"
            :aria-label="confirmingDeleteId === account.id ? 'تأیید حذف' : 'حذف اکانت'"
            @click="confirmDelete(account.id)"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path
                fill-rule="evenodd"
                d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z"
                clip-rule="evenodd"
              />
            </svg>
          </button>
        </div>

        <button
          v-if="account.status === 'needs_reauth'"
          type="button"
          class="mr-4 mt-0.5 text-xs font-medium text-amber-600 hover:text-amber-700"
          @click="reauthTarget = account"
        >
          نیاز به رمز جدید — کلیک کنید
        </button>
      </li>
    </ul>

    <p v-else class="px-1 text-sm text-gray-400">هنوز اکانتی متصل نکرده‌اید.</p>

    <AddAccountModal v-if="showAddModal" @close="showAddModal = false" @added="(a) => emit('change', a.id)" />
    <ReauthModal v-if="reauthTarget" :account="reauthTarget" @close="reauthTarget = null" />
  </div>
</template>
