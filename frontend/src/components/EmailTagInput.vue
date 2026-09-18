<script setup>
import { ref } from 'vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  placeholder: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const inputValue = ref('')
const error = ref('')

// Adds `raw` to `list` (a local working copy, not the prop directly) and
// returns the resulting list — multiple additions in one call must share
// one working copy and end in a single emit, since `props.modelValue`
// doesn't reflect an emit until Vue re-renders, so emitting once per
// address in a loop would have each emit overwrite the previous one.
// Invalid addresses are collected into `invalid` rather than written
// straight to `error.value`, so one bad address in a batch of several
// doesn't have its message overwritten by a later valid one.
function tryAdd(list, raw, invalid) {
  const email = raw.trim().replace(/,+$/, '')
  if (!email) return list

  if (!EMAIL_PATTERN.test(email)) {
    invalid.push(email)
    return list
  }

  return list.includes(email) ? list : [...list, email]
}

function addEmail(raw) {
  const invalid = []
  const updated = tryAdd(props.modelValue, raw, invalid)
  error.value = invalid.length ? `«${invalid[0]}» یک آدرس ایمیل معتبر نیست` : ''
  if (updated !== props.modelValue) {
    emit('update:modelValue', updated)
  }
  return invalid.length === 0
}

function commitPending() {
  if (!inputValue.value.trim()) return

  // A comma-containing value can land in the field all at once (autofill,
  // IME, a value set programmatically) rather than via individual
  // keystrokes — the per-keystroke comma handling below never fires for
  // those, so this splits on every commit as a safety net.
  const parts = inputValue.value.split(',').map((part) => part.trim())
  const invalid = []
  let list = props.modelValue
  parts.filter(Boolean).forEach((part) => {
    list = tryAdd(list, part, invalid)
  })

  if (list !== props.modelValue) {
    emit('update:modelValue', list)
  }

  if (invalid.length) {
    error.value = `«${invalid.join('، ')}» یک آدرس ایمیل معتبر نیست`
    inputValue.value = invalid.join(', ')
  } else {
    error.value = ''
    inputValue.value = ''
  }
}

function handleKeydown(event) {
  if (event.key === 'Enter' || event.key === ',') {
    if (inputValue.value.trim()) {
      event.preventDefault()
      commitPending()
    }
    return
  }

  if (event.key === 'Backspace' && !inputValue.value && props.modelValue.length) {
    removeChip(props.modelValue.length - 1)
  }
}

function handlePaste(event) {
  const text = event.clipboardData?.getData('text') ?? ''
  if (!text.includes(',')) return

  event.preventDefault()
  const invalid = []
  let list = props.modelValue
  text
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean)
    .forEach((part) => {
      list = tryAdd(list, part, invalid)
    })
  if (list !== props.modelValue) {
    emit('update:modelValue', list)
  }
  if (invalid.length) {
    error.value = `«${invalid.join('، ')}» یک آدرس ایمیل معتبر نیست`
    inputValue.value = invalid.join(', ')
  } else {
    error.value = ''
    inputValue.value = ''
  }
}

function handleBlur() {
  commitPending()
}

function removeChip(index) {
  const updated = [...props.modelValue]
  updated.splice(index, 1)
  emit('update:modelValue', updated)
}
</script>

<template>
  <div>
    <div
      class="flex min-h-[42px] flex-wrap items-center gap-1.5 rounded-lg border border-gray-200 px-2 py-1.5 focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500"
    >
      <span
        v-for="(email, index) in modelValue"
        :key="email"
        dir="ltr"
        class="flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700"
      >
        {{ email }}
        <button
          type="button"
          class="rounded-full p-0.5 text-indigo-400 hover:bg-indigo-100 hover:text-indigo-600"
          aria-label="حذف آدرس"
          @click="removeChip(index)"
        >
          <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path
              d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"
            />
          </svg>
        </button>
      </span>

      <input
        v-model="inputValue"
        type="text"
        dir="ltr"
        :placeholder="modelValue.length ? '' : placeholder"
        class="min-w-[120px] flex-1 border-none p-0.5 text-sm focus:outline-none focus:ring-0"
        @keydown="handleKeydown"
        @paste="handlePaste"
        @blur="handleBlur"
      />
    </div>
    <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
  </div>
</template>
