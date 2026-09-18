import { defineStore } from 'pinia'
import { api } from '../lib/api'

export const useFoldersStore = defineStore('folders', {
  state: () => ({
    items: [],
    activeId: null,
    loadingAccountId: null,
    error: null,
  }),

  getters: {
    active: (state) => state.items.find((folder) => folder.id === state.activeId) ?? null,
  },

  actions: {
    /**
     * Refresh + list an account's folders (the backend syncs from the live
     * IMAP server on every call — CLAUDE.md §5 Phase 2).
     */
    async fetch(accountId) {
      this.loadingAccountId = accountId
      this.error = null

      try {
        this.items = await api.get(`/email-accounts/${accountId}/folders`)

        if (!this.activeId || !this.items.some((folder) => folder.id === this.activeId)) {
          const inbox = this.items.find((folder) => folder.type === 'inbox')
          this.setActive(inbox?.id ?? this.items[0]?.id ?? null)
        }
      } catch (error) {
        this.error = error
      } finally {
        this.loadingAccountId = null
      }
    },

    setActive(id) {
      this.activeId = id
    },

    reset() {
      this.items = []
      this.activeId = null
      this.error = null
    },

    /**
     * Adjust a folder's unread_count locally (e.g. after toggling a message's
     * read state) without triggering another live IMAP sync just to refresh
     * a badge.
     */
    adjustUnreadCount(folderId, delta) {
      const folder = this.items.find((item) => item.id === folderId)
      if (folder) {
        folder.unread_count = Math.max(0, folder.unread_count + delta)
      }
    },

    setUnreadCount(folderId, count) {
      const folder = this.items.find((item) => item.id === folderId)
      if (folder) {
        folder.unread_count = count
      }
    },

    /**
     * Record locally that a folder's messages were just synced, so we don't
     * ask for another automatic sync next time it's opened in this session.
     */
    markSynced(folderId) {
      const folder = this.items.find((item) => item.id === folderId)
      if (folder) {
        folder.last_synced_at = new Date().toISOString()
      }
    },
  },
})
