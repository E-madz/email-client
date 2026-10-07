<script setup>
import { reactive, ref } from 'vue'
import BaseModal from './BaseModal.vue'
import { useAccountsStore } from '../stores/accounts'
import { ApiError } from '../lib/api'

const emit = defineEmits(['close', 'added'])

const accounts = useAccountsStore()
const form = reactive({ email: '', password: '' })
const submitting = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

async function handleSubmit() {
  submitting.value = true
  errorMessage.value = ''
  fieldErrors.value = {}

  try {
    const account = await accounts.add(form)
    emit('added', account)
    emit('close')
  } catch (error) {
    if (error instanceof ApiError) {
      fieldErrors.value = error.errors ?? {}
      errorMessage.value =
        error.status === 503
          ? 'اتصال به سرور ایمیل برقرار نشد. لطفاً کمی بعد دوباره تلاش کنید.'
          : (error.errors?.email?.[0] ?? error.errors?.password?.[0] ?? error.message)
    } else {
      errorMessage.value = 'خطای غیرمنتظره‌ای رخ داد.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal title="افزودن اکانت ایمیل" @close="$emit('close')">
    <p class="mb-4 text-sm text-gray-500">فقط آدرس ایمیل و رمز عبور صندوق پستی خود را وارد کنید.</p>

    <form class="space-y-4" @submit.prevent="handleSubmit">
      <div>
        <label for="account-email" class="mb-1.5 block text-sm font-medium text-gray-700">آدرس ایمیل</label>
        <input
          id="account-email"
          v-model="form.email"
          type="email"
          required
          dir="ltr"
          placeholder="you@yourdomain.ir"
          class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
        />
      </div>

      <div>
        <label for="account-password" class="mb-1.5 block text-sm font-medium text-gray-700">رمز عبور صندوق پستی</label>
        <input
          id="account-password"
          v-model="form.password"
          type="password"
          required
          class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
        />
      </div>

      <p v-if="errorMessage" class="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-700">{{ errorMessage }}</p>

      <button
        type="submit"
        :disabled="submitting"
        class="brand-gradient flex w-full items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-indigo-500/25 transition hover:shadow-indigo-500/40 hover:brightness-110 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:brightness-100"
      >
        <span v-if="submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
        {{ submitting ? 'در حال بررسی اتصال…' : 'اتصال اکانت' }}
      </button>
    </form>
  </BaseModal>
</template>
