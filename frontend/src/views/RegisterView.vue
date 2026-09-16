<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../lib/api'

const router = useRouter()
const auth = useAuthStore()

const form = reactive({
  name: '',
  username: '',
  email: '',
  password: '',
  password_confirmation: '',
})
const errorMessage = ref('')
const fieldErrors = ref({})

async function handleSubmit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  try {
    await auth.register(form)
    router.push({ name: 'mailbox' })
  } catch (error) {
    if (error instanceof ApiError) {
      fieldErrors.value = error.errors ?? {}
      errorMessage.value = error.status === 422 ? 'لطفاً خطاهای فرم را برطرف کنید.' : error.message
    } else {
      errorMessage.value = 'خطای غیرمنتظره‌ای رخ داد.'
    }
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10">
    <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-lg shadow-gray-200/60">
      <div class="mb-8 text-center">
        <h1 class="text-xl font-bold text-gray-900">ساخت حساب کاربری</h1>
        <p class="mt-1 text-sm text-gray-500">برای شروع، اطلاعات زیر را وارد کنید</p>
      </div>

      <form class="space-y-4" @submit.prevent="handleSubmit">
        <div>
          <label for="name" class="mb-1 block text-sm font-medium text-gray-700">نام</label>
          <input
            id="name"
            v-model="form.name"
            type="text"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
          <p v-if="fieldErrors.name" class="mt-1 text-xs text-red-600">{{ fieldErrors.name[0] }}</p>
        </div>

        <div>
          <label for="username" class="mb-1 block text-sm font-medium text-gray-700">نام کاربری</label>
          <input
            id="username"
            v-model="form.username"
            type="text"
            autocomplete="username"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
          <p v-if="fieldErrors.username" class="mt-1 text-xs text-red-600">{{ fieldErrors.username[0] }}</p>
        </div>

        <div>
          <label for="email" class="mb-1 block text-sm font-medium text-gray-700">ایمیل</label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            autocomplete="email"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
          <p v-if="fieldErrors.email" class="mt-1 text-xs text-red-600">{{ fieldErrors.email[0] }}</p>
        </div>

        <div>
          <label for="password" class="mb-1 block text-sm font-medium text-gray-700">رمز عبور</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
          <p v-if="fieldErrors.password" class="mt-1 text-xs text-red-600">{{ fieldErrors.password[0] }}</p>
        </div>

        <div>
          <label for="password_confirmation" class="mb-1 block text-sm font-medium text-gray-700">
            تکرار رمز عبور
          </label>
          <input
            id="password_confirmation"
            v-model="form.password_confirmation"
            type="password"
            autocomplete="new-password"
            required
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
        </div>

        <p v-if="errorMessage" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>

        <button
          type="submit"
          :disabled="auth.submitting"
          class="flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <span
            v-if="auth.submitting"
            class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
          />
          {{ auth.submitting ? 'در حال ثبت‌نام…' : 'ثبت‌نام' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-gray-500">
        قبلاً ثبت‌نام کرده‌اید؟
        <RouterLink :to="{ name: 'login' }" class="font-medium text-indigo-600 hover:text-indigo-700">
          وارد شوید
        </RouterLink>
      </p>
    </div>
  </div>
</template>
