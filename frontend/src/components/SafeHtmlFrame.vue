<script setup>
/**
 * Renders untrusted email HTML in a sandboxed iframe instead of v-html'ing
 * it into the main document. `allow-same-origin` is deliberately left out —
 * any script in the email body runs (if at all) in an isolated opaque
 * origin with no access to our app's DOM, cookies, or localStorage token.
 * Height auto-fits the content via postMessage since the parent can't reach
 * across that origin boundary to read scrollHeight directly.
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  html: { type: String, default: '' },
})

const frameId = `email-frame-${Math.random().toString(36).slice(2)}`
const height = ref(200)

const srcdoc = computed(() => `<!doctype html>
<html dir="auto">
  <head>
    <meta charset="utf-8" />
    <base target="_blank" />
    <style>
      html, body { margin: 0; padding: 0; }
      body {
        font-family: Vazirmatn, ui-sans-serif, system-ui, sans-serif;
        color: #1f2937;
        padding: 1rem;
        word-wrap: break-word;
        overflow-wrap: anywhere;
      }
      img { max-width: 100%; height: auto; }
      table { max-width: 100%; }
    </style>
  </head>
  <body dir="auto">${props.html || '<p style="color:#9ca3af">این پیام بدون محتوا است.</p>'}</body>
  <script>
    function reportHeight() {
      var h = document.body.scrollHeight;
      parent.postMessage({ type: 'email-frame-resize', frameId: '${frameId}', height: h }, '*');
    }
    window.addEventListener('load', reportHeight);
    new ResizeObserver(reportHeight).observe(document.body);
  <\/script>
</html>`)

function handleMessage(event) {
  if (event.data?.type === 'email-frame-resize' && event.data.frameId === frameId) {
    height.value = Math.max(120, event.data.height + 24)
  }
}

onMounted(() => window.addEventListener('message', handleMessage))
onUnmounted(() => window.removeEventListener('message', handleMessage))

watch(
  () => props.html,
  () => {
    height.value = 200
  },
)
</script>

<template>
  <iframe
    :srcdoc="srcdoc"
    sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"
    class="w-full border-0"
    :style="{ height: `${height}px` }"
    title="محتوای ایمیل"
  />
</template>
