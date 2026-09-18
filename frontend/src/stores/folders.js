import { defineStore } from 'pinia'
import { api } from '../lib/api'

/**
 * The sentinel id for the virtual "Starred" view — not a real synced IMAP
 * folder (CLAUDE.md §8), just a cross-folder query the backend answers
 * locally. Exported so components can compare against it without repeating
 * the literal string.
 */
export const STARRED_ID = 'starred'

const STARRED_FOLDER = {
  id: STARRED_ID,
  name: 'Starred',
  type: 'starred',
  display_name: 'Starred',
  unread_count: 0,
  last_synced_at: null,
}

export const useFoldersStore = defineStore('folders', {
  state: () => ({
    items: [],
    activeId: null,
    loadingAccountId: null,
    error: null,
    creating: false,
    createError: null,
  }),

  getters: {
    active: (state) => {
      if (state.activeId === STARRED_ID) return STARRED_FOLDER
      return state.items.find((folder) => folder.id === state.activeId) ?? null
    },
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

        const activeStillValid = this.activeId === STARRED_ID || this.items.some((folder) => folder.id === this.activeId)
        if (!this.activeId || !activeStillValid) {
          const inbox = this.items.find((folder) => folder.type === 'inbox')
          this.setActive(inbox?.id ?? this.items[0]?.id ?? null)
        }
      } catch (error) {
        this.error = error
      } finally {
        this.loadingAccountId = null
      }
    },

    /**
     * A real IMAP `CREATE`, not just a local row — see FolderCreationService.
     */
    async createFolder(accountId, name) {
      this.creating = true
      this.createError = null

      try {
        const folder = await api.post(`/email-accounts/${accountId}/folders`, { name })
        this.items = [...this.items, folder]
        return folder
      } catch (error) {
        this.createError = error
        throw error
      } finally {
        this.creating = false
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
