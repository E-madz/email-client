import { defineStore } from 'pinia'
import { api } from '../lib/api'

function emptyDraft() {
  return {
    to: '',
    cc: '',
    bcc: '',
    subject: '',
    bodyText: '',
    attachments: [],
  }
}

/**
 * Phase 4a compose/send only (CLAUDE.md §8) — no Drafts persistence and no
 * Reply/Forward threading yet, both deferred to Phase 4b.
 */
export const useComposeStore = defineStore('compose', {
  state: () => ({
    isOpen: false,
    accountId: null,
    draft: emptyDraft(),
    sending: false,
    error: null,
  }),

  actions: {
    open(accountId) {
      this.accountId = accountId
      this.draft = emptyDraft()
      this.error = null
      this.isOpen = true
    },

    close() {
      this.isOpen = false
    },

    addAttachments(files) {
      this.draft.attachments = [...this.draft.attachments, ...files]
    },

    removeAttachment(index) {
      this.draft.attachments = this.draft.attachments.filter((_, i) => i !== index)
    },

    /**
     * Plain-textarea body (CLAUDE.md §8 — no rich-text editor dependency
     * added, not in the original tech stack) turned into minimal HTML by
     * escaping it and swapping newlines for <br>, so paragraphs survive the
     * round trip through the backend's body_html column.
     */
    buildBodyHtml() {
      const escaped = this.draft.bodyText.replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[ch])
      return `<p>${escaped.replace(/\n/g, '<br>')}</p>`
    },

    async send() {
      this.sending = true
      this.error = null

      try {
        const formData = new FormData()
        splitEmails(this.draft.to).forEach((email) => formData.append('to[]', email))
        splitEmails(this.draft.cc).forEach((email) => formData.append('cc[]', email))
        splitEmails(this.draft.bcc).forEach((email) => formData.append('bcc[]', email))
        formData.append('subject', this.draft.subject)
        formData.append('body_html', this.buildBodyHtml())
        this.draft.attachments.forEach((file) => formData.append('attachments[]', file))

        await api.postForm(`/email-accounts/${this.accountId}/messages`, formData)
        this.isOpen = false
      } catch (error) {
        this.error = error
        throw error
      } finally {
        this.sending = false
      }
    },
  },
})

function splitEmails(value) {
  return value
    .split(',')
    .map((email) => email.trim())
    .filter(Boolean)
}
