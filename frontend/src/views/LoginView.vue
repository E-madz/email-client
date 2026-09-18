<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../lib/api'

const router = useRouter()
const auth = useAuthStore()

const form = reactive({ username: '', password: '' })
const errorMessage = ref('')
const fieldErrors = ref({})

async function handleSubmit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  try {
    await auth.login(form)
    router.push({ name: 'mailbox' })
  } catch (error) {
    if (error instanceof ApiError) {
      fieldErrors.value = error.errors ?? {}
      errorMessage.value = error.status === 422 ? 'نام کاربری یا رمز عبور اشتباه است.' : error.message
    } else {
      errorMessage.value = 'خطای غیرمنتظره‌ای رخ داد.'
    }
  }
}
</script>

<template>
  <div
    class="flex min-h-screen items-center justify-center px-4"
    style="background: radial-gradient(circle at 15% 15%, #eef2ff 0%, transparent 45%), radial-gradient(circle at 85% 85%, #f5f3ff 0%, transparent 45%), #f8fafc"
  >
    <div class="w-full max-w-sm rounded-3xl border border-gray-100 bg-white p-8 shadow-xl shadow-indigo-950/5">
      <div class="mb-8 text-center">
        <div class="brand-gradient mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-lg shadow-indigo-500/30">
          <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"
            />
          </svg>
        </div>
        <h1 class="text-2xl font-bold tracking-tight text-gray-900">خوش آمدید</h1>
        <p class="mt-1.5 text-sm text-gray-500">با نام کاربری خود وارد ایمیل من شوید</p>
      </div>

      <form class="space-y-4" @submit.prevent="handleSubmit">
        <div>
          <label for="username" class="mb-1.5 block text-sm font-medium text-gray-700">نام کاربری</label>
          <input
            id="username"
            v-model="form.username"
            type="text"
            autocomplete="username"
            required
            class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
          />
          <p v-if="fieldErrors.username" class="mt-1 text-xs text-red-600">{{ fieldErrors.username[0] }}</p>
        </div>

        <div>
          <label for="password" class="mb-1.5 block text-sm font-medium text-gray-700">رمز عبور</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            autocomplete="current-password"
            required
            class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
          />
        </div>

        <p v-if="errorMessage" class="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-700">{{ errorMessage }}</p>

        <button
          type="submit"
          :disabled="auth.submitting"
          class="brand-gradient flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:shadow-indigo-500/40 hover:brightness-110 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:brightness-100"
        >
          <span
            v-if="auth.submitting"
            class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
          />
          {{ auth.submitting ? 'در حال ورود…' : 'ورود' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-gray-500">
        حساب ندارید؟
        <RouterLink :to="{ name: 'register' }" class="font-semibold text-indigo-600 hover:text-indigo-700">
          ثبت‌نام کنید
        </RouterLink>
      </p>
    </div>
  </div>
</template>
