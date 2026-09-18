import { defineStore } from 'pinia'
import { api } from '../lib/api'
import { useMessagesStore } from './messages'

function emptyDraft() {
  return {
    to: [],
    cc: [],
    bcc: [],
    subject: '',
    bodyText: '',
    attachments: [],
  }
}

function plainTextFromMessage(message) {
  if (message.body_text) return message.body_text
  const stripped = (message.body_html ?? '').replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n\n')
  const div = document.createElement('div')
  div.innerHTML = stripped
  return (div.textContent ?? '').trim()
}

function quote(message) {
  const date = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(message.date))
  const original = plainTextFromMessage(message)
    .split('\n')
    .map((line) => `> ${line}`)
    .join('\n')
  return `\n\n${date}، ${message.from?.name || message.from?.email} نوشت:\n${original}`
}

function forwardBody(message) {
  const original = plainTextFromMessage(message)
  return (
    `\n\n---------- پیام ارجاعی ----------\n` +
    `از: ${message.from?.name || message.from?.email}\n` +
    `تاریخ: ${new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(message.date))}\n` +
    `موضوع: ${message.subject}\n\n${original}`
  )
}

/**
 * Phase 4b: compose now covers plain send (Phase 4a), Reply/Forward
 * threading, and Drafts save/edit — CLAUDE.md §8. Editing an existing
 * draft's attachments (§8 item 62): its already-saved attachments come back
 * as metadata only (`existingAttachments`, not re-uploadable browser `File`
 * objects) and are kept server-side via `keep_attachment_ids` unless the
 * user explicitly removes one; newly added files are uploaded alongside.
 */
export const useComposeStore = defineStore('compose', {
  state: () => ({
    isOpen: false,
    accountId: null,
    draft: emptyDraft(),
    // Metadata-only list of a draft's already-saved attachments
    // ({id, filename, mime_type, size_bytes}) — kept unless removed.
    existingAttachments: [],
    sending: false,
    savingDraft: false,
    error: null,
    // 'new' | 'reply' | 'forward' | 'draft'
    mode: 'new',
    editingDraftId: null,
    replyToMessageId: null,
  }),

  actions: {
    open(accountId) {
      this.accountId = accountId
      this.draft = emptyDraft()
      this.existingAttachments = []
      this.mode = 'new'
      this.editingDraftId = null
      this.replyToMessageId = null
      this.error = null
      this.isOpen = true
    },

    openReply(accountId, message) {
      this.accountId = accountId
      this.draft = {
        ...emptyDraft(),
        to: message.from?.email ? [message.from.email] : [],
        subject: message.subject?.startsWith('Re:') ? message.subject : `Re: ${message.subject ?? ''}`,
        bodyText: quote(message),
      }
      this.existingAttachments = []
      this.mode = 'reply'
      this.editingDraftId = null
      this.replyToMessageId = message.id
      this.error = null
      this.isOpen = true
    },

    openForward(accountId, message) {
      this.accountId = accountId
      this.draft = {
        ...emptyDraft(),
        subject: message.subject?.startsWith('Fwd:') ? message.subject : `Fwd: ${message.subject ?? ''}`,
        bodyText: forwardBody(message),
      }
      this.existingAttachments = []
      this.mode = 'forward'
      this.editingDraftId = null
      this.replyToMessageId = null
      this.error = null
      this.isOpen = true
    },

    /**
     * `message` is a full MessageDetailResource (already fetched via
     * GET /messages/{id}) so body_html/body_text/attachments are available.
     */
    openDraft(accountId, message) {
      this.accountId = accountId
      this.draft = {
        to: (message.to ?? []).map((r) => r.email),
        cc: (message.cc ?? []).map((r) => r.email),
        bcc: (message.bcc ?? []).map((r) => r.email),
        subject: message.subject ?? '',
        bodyText: plainTextFromMessage(message),
        attachments: [],
      }
      this.existingAttachments = message.attachments ?? []
      this.mode = 'draft'
      this.editingDraftId = message.id
      this.replyToMessageId = null
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

    removeExistingAttachment(id) {
      this.existingAttachments = this.existingAttachments.filter((attachment) => attachment.id !== id)
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

    buildFormData() {
      const formData = new FormData()
      this.draft.to.forEach((email) => formData.append('to[]', email))
      this.draft.cc.forEach((email) => formData.append('cc[]', email))
      this.draft.bcc.forEach((email) => formData.append('bcc[]', email))
      formData.append('subject', this.draft.subject)
      formData.append('body_html', this.buildBodyHtml())
      this.draft.attachments.forEach((file) => formData.append('attachments[]', file))
      return formData
    },

    async send() {
      this.sending = true
      this.error = null

      try {
        const formData = this.buildFormData()
        if (this.replyToMessageId) {
          formData.append('in_reply_to_message_id', String(this.replyToMessageId))
        }

        await api.postForm(`/email-accounts/${this.accountId}/messages`, formData)

        // A draft being sent is done being a draft — clean it up (moves to
        // Trash, recoverable, same as any other message delete).
        if (this.editingDraftId) {
          useMessagesStore()
            .deleteMessage(this.editingDraftId)
            .catch(() => {})
        }

        this.isOpen = false
      } catch (error) {
        this.error = error
        throw error
      } finally {
        this.sending = false
      }
    },

    async saveDraft() {
      this.savingDraft = true
      this.error = null

      try {
        const formData = this.buildFormData()

        if (this.editingDraftId) {
          this.existingAttachments.forEach((attachment) => {
            formData.append('keep_attachment_ids[]', String(attachment.id))
          })
          await api.putForm(`/drafts/${this.editingDraftId}`, formData)
        } else {
          await api.postForm(`/email-accounts/${this.accountId}/drafts`, formData)
        }

        this.isOpen = false
      } catch (error) {
        this.error = error
        throw error
      } finally {
        this.savingDraft = false
      }
    },
  },
})
