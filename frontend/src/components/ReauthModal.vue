<script setup>
import { ref } from 'vue'
import BaseModal from './BaseModal.vue'
import { useAccountsStore } from '../stores/accounts'
import { ApiError } from '../lib/api'

const props = defineProps({
  account: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const accounts = useAccountsStore()
const password = ref('')
const submitting = ref(false)
const errorMessage = ref('')

async function handleSubmit() {
  submitting.value = true
  errorMessage.value = ''

  try {
    await accounts.reauth(props.account.id, password.value)
    emit('close')
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError && error.status === 422
        ? 'رمز عبور وارد شده صحیح نیست.'
        : 'اتصال به سرور برقرار نشد.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal title="نیاز به رمز عبور جدید" @close="$emit('close')">
    <p class="mb-4 text-sm text-gray-500">
      اتصال به <bdi dir="ltr" class="font-medium text-gray-700">{{ account.email }}</bdi>
      قطع شده — احتمالاً رمز عبور آن تغییر کرده است. رمز جدید را وارد کنید.
    </p>

    <form class="space-y-4" @submit.prevent="handleSubmit">
      <input
        v-model="password"
        type="password"
        required
        placeholder="رمز عبور جدید"
        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      />

      <p v-if="errorMessage" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>

      <button
        type="submit"
        :disabled="submitting"
        class="flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
      >
        <span v-if="submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
        {{ submitting ? 'در حال بررسی…' : 'به‌روزرسانی' }}
      </button>
    </form>
  </BaseModal>
</template>
