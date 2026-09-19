<script setup>
import { ref, watch } from 'vue'
import { api, ApiError } from '../lib/api'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  placeholder: { type: String, default: '' },
  // The email account whose message history suggestions are drawn from
  // (ContactSuggestionService, §8 — no dedicated contacts table, just a
  // live query over already-synced messages). Suggestions are skipped
  // entirely when this is null (e.g. no account selected yet).
  accountId: { type: Number, default: null },
})

const emit = defineEmits(['update:modelValue'])

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const inputValue = ref('')
const error = ref('')

const suggestions = ref([])
const activeSuggestionIndex = ref(-1)
const showSuggestions = ref(false)
let suggestDebounceTimer = null
let suggestRequestToken = 0

async function fetchSuggestions(query) {
  if (!props.accountId || !query.trim()) {
    suggestions.value = []
    return
  }

  const requestToken = ++suggestRequestToken

  try {
    const result = await api.get(`/email-accounts/${props.accountId}/contacts?q=${encodeURIComponent(query.trim())}`)
    // A slower, now-stale request could resolve after a newer one — ignore
    // it rather than flashing outdated suggestions over fresh input.
    if (requestToken !== suggestRequestToken) return

    suggestions.value = result.filter((contact) => !props.modelValue.includes(contact.email))
    activeSuggestionIndex.value = suggestions.value.length ? 0 : -1
  } catch (err) {
    if (!(err instanceof ApiError)) throw err
    suggestions.value = []
  }
}

watch(inputValue, (value) => {
  clearTimeout(suggestDebounceTimer)
  showSuggestions.value = true

  if (!value.trim()) {
    suggestions.value = []
    return
  }

  suggestDebounceTimer = setTimeout(() => fetchSuggestions(value), 200)
})

function selectSuggestion(contact) {
  addEmail(contact.email)
  inputValue.value = ''
  suggestions.value = []
  showSuggestions.value = false
}

function closeSuggestions() {
  // Deferred so a click on a suggestion (which blurs the input first)
  // still registers before the list disappears.
  setTimeout(() => {
    showSuggestions.value = false
  }, 150)
}

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
  const hasSuggestions = showSuggestions.value && suggestions.value.length > 0

  if (event.key === 'ArrowDown' && hasSuggestions) {
    event.preventDefault()
    activeSuggestionIndex.value = (activeSuggestionIndex.value + 1) % suggestions.value.length
    return
  }

  if (event.key === 'ArrowUp' && hasSuggestions) {
    event.preventDefault()
    activeSuggestionIndex.value = (activeSuggestionIndex.value - 1 + suggestions.value.length) % suggestions.value.length
    return
  }

  if (event.key === 'Escape' && hasSuggestions) {
    showSuggestions.value = false
    return
  }

  if (event.key === 'Enter' || event.key === ',') {
    event.preventDefault()

    if (hasSuggestions && activeSuggestionIndex.value >= 0) {
      selectSuggestion(suggestions.value[activeSuggestionIndex.value])
      return
    }

    if (inputValue.value.trim()) {
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
  closeSuggestions()
  commitPending()
}

function removeChip(index) {
  const updated = [...props.modelValue]
  updated.splice(index, 1)
  emit('update:modelValue', updated)
}
</script>

<template>
  <div class="relative">
    <div
      class="flex min-h-[44px] flex-wrap items-center gap-1.5 rounded-xl border border-gray-200 bg-gray-50/60 px-2.5 py-1.5 transition focus-within:border-indigo-400 focus-within:bg-white focus-within:ring-4 focus-within:ring-indigo-500/10"
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
        class="min-w-[120px] flex-1 border-none bg-transparent p-0.5 text-sm focus:outline-none focus:ring-0"
        @keydown="handleKeydown"
        @paste="handlePaste"
        @blur="handleBlur"
        @focus="showSuggestions = true"
      />
    </div>
    <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>

    <ul
      v-if="showSuggestions && suggestions.length"
      class="absolute z-10 mt-1 w-full max-w-xs overflow-hidden rounded-xl border border-gray-100 bg-white py-1 shadow-lg shadow-indigo-950/10"
    >
      <li
        v-for="(contact, index) in suggestions"
        :key="contact.email"
        dir="ltr"
        class="cursor-pointer px-3 py-2 text-sm transition"
        :class="index === activeSuggestionIndex ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-50'"
        @mousedown.prevent="selectSuggestion(contact)"
        @mouseenter="activeSuggestionIndex = index"
      >
        <span v-if="contact.name" class="font-medium">{{ contact.name }}</span>
        <span :class="contact.name ? 'ml-1.5 text-xs text-gray-400' : ''">{{ contact.email }}</span>
      </li>
    </ul>
  </div>
</template>
