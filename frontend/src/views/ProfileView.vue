<script setup>
import { reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { ApiError } from '../lib/api'

const router = useRouter()
const auth = useAuthStore()

const profileForm = reactive({
  name: auth.user?.name ?? '',
  username: auth.user?.username ?? '',
})
const profileErrorMessage = ref('')
const profileFieldErrors = ref({})
const profileSuccess = ref(false)

watch(
  () => auth.user,
  (user) => {
    if (user) {
      profileForm.name = user.name
      profileForm.username = user.username
    }
  },
)

async function handleProfileSubmit() {
  profileErrorMessage.value = ''
  profileFieldErrors.value = {}
  profileSuccess.value = false

  try {
    await auth.updateProfile(profileForm)
    profileSuccess.value = true
  } catch (error) {
    if (error instanceof ApiError) {
      profileFieldErrors.value = error.errors ?? {}
      profileErrorMessage.value = error.status === 422 ? 'لطفاً خطاهای فرم را برطرف کنید.' : error.message
    } else {
      profileErrorMessage.value = 'خطای غیرمنتظره‌ای رخ داد.'
    }
  }
}

const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const passwordErrorMessage = ref('')
const passwordFieldErrors = ref({})
const passwordSuccess = ref(false)

async function handlePasswordSubmit() {
  passwordErrorMessage.value = ''
  passwordFieldErrors.value = {}
  passwordSuccess.value = false

  try {
    await auth.updatePassword(passwordForm)
    passwordSuccess.value = true
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
  } catch (error) {
    if (error instanceof ApiError) {
      passwordFieldErrors.value = error.errors ?? {}
      passwordErrorMessage.value = error.status === 422 ? 'لطفاً خطاهای فرم را برطرف کنید.' : error.message
    } else {
      passwordErrorMessage.value = 'خطای غیرمنتظره‌ای رخ داد.'
    }
  }
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 px-4 py-10">
    <div class="mx-auto max-w-lg space-y-6">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-gray-900">پروفایل کاربری</h1>
        <button
          type="button"
          class="rounded-xl px-3 py-2 text-sm font-medium text-gray-500 transition hover:bg-gray-100 hover:text-gray-700"
          @click="router.push({ name: 'mailbox' })"
        >
          بازگشت به ایمیل
        </button>
      </div>

      <div class="rounded-3xl border border-gray-100 bg-white p-8 shadow-xl shadow-indigo-950/5">
        <h2 class="mb-1 text-lg font-bold text-gray-900">اطلاعات حساب</h2>
        <p class="mb-6 text-sm text-gray-500">نام و نام کاربری خود را ویرایش کنید.</p>

        <form class="space-y-4" @submit.prevent="handleProfileSubmit">
          <div>
            <label for="profile-name" class="mb-1.5 block text-sm font-medium text-gray-700">نام</label>
            <input
              id="profile-name"
              v-model="profileForm.name"
              type="text"
              required
              class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
            />
            <p v-if="profileFieldErrors.name" class="mt-1 text-xs text-red-600">{{ profileFieldErrors.name[0] }}</p>
          </div>

          <div>
            <label for="profile-username" class="mb-1.5 block text-sm font-medium text-gray-700">نام کاربری</label>
            <input
              id="profile-username"
              v-model="profileForm.username"
              type="text"
              autocomplete="username"
              required
              class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
            />
            <p v-if="profileFieldErrors.username" class="mt-1 text-xs text-red-600">{{ profileFieldErrors.username[0] }}</p>
          </div>

          <p v-if="profileErrorMessage" class="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-700">{{ profileErrorMessage }}</p>
          <p v-if="profileSuccess" class="rounded-xl bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-700">اطلاعات با موفقیت به‌روزرسانی شد.</p>

          <button
            type="submit"
            :disabled="auth.submitting"
            class="brand-gradient flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:shadow-indigo-500/40 hover:brightness-110 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:brightness-100"
          >
            <span v-if="auth.submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
            {{ auth.submitting ? 'در حال ذخیره…' : 'ذخیره تغییرات' }}
          </button>
        </form>
      </div>

      <div class="rounded-3xl border border-gray-100 bg-white p-8 shadow-xl shadow-indigo-950/5">
        <h2 class="mb-1 text-lg font-bold text-gray-900">تغییر رمز عبور</h2>
        <p class="mb-6 text-sm text-gray-500">این رمز، رمز عبور ورود به اپلیکیشن است، نه رمز صندوق ایمیل.</p>

        <form class="space-y-4" @submit.prevent="handlePasswordSubmit">
          <div>
            <label for="current-password" class="mb-1.5 block text-sm font-medium text-gray-700">رمز عبور فعلی</label>
            <input
              id="current-password"
              v-model="passwordForm.current_password"
              type="password"
              autocomplete="current-password"
              required
              class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
            />
            <p v-if="passwordFieldErrors.current_password" class="mt-1 text-xs text-red-600">
              {{ passwordFieldErrors.current_password[0] }}
            </p>
          </div>

          <div>
            <label for="new-password" class="mb-1.5 block text-sm font-medium text-gray-700">رمز عبور جدید</label>
            <input
              id="new-password"
              v-model="passwordForm.password"
              type="password"
              autocomplete="new-password"
              required
              class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
            />
            <p v-if="passwordFieldErrors.password" class="mt-1 text-xs text-red-600">{{ passwordFieldErrors.password[0] }}</p>
          </div>

          <div>
            <label for="new-password-confirmation" class="mb-1.5 block text-sm font-medium text-gray-700">
              تکرار رمز عبور جدید
            </label>
            <input
              id="new-password-confirmation"
              v-model="passwordForm.password_confirmation"
              type="password"
              autocomplete="new-password"
              required
              class="w-full rounded-xl border border-gray-200 bg-gray-50/60 px-3.5 py-2.5 text-sm transition focus:border-indigo-400 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10"
            />
          </div>

          <p v-if="passwordErrorMessage" class="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-700">{{ passwordErrorMessage }}</p>
          <p v-if="passwordSuccess" class="rounded-xl bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-700">رمز عبور با موفقیت تغییر کرد.</p>

          <button
            type="submit"
            :disabled="auth.submitting"
            class="brand-gradient flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:shadow-indigo-500/40 hover:brightness-110 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:brightness-100"
          >
            <span v-if="auth.submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
            {{ auth.submitting ? 'در حال ذخیره…' : 'تغییر رمز عبور' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>
