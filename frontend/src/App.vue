<script setup>
import { watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from './stores/auth'

// A logout (explicit, or a forced one from a 401 response) only changes
// store state — it isn't itself a navigation, so the router guard never
// re-runs on its own. Watching auth state here, once, covers both cases.
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

watch(
  () => auth.isAuthenticated,
  (isAuthenticated) => {
    if (!isAuthenticated && !route.meta.guestOnly) {
      router.push({ name: 'login' })
    }
  },
)
</script>

<template>
  <RouterView />
</template>
