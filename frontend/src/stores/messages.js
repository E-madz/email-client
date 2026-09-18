import { defineStore } from 'pinia'
import { api } from '../lib/api'
import { useFoldersStore, STARRED_ID } from './folders'

/**
 * The sentinel id for the virtual "Search" view — like `STARRED_ID`, not a
 * real folder, just a marker so per-message logic that reads
 * `message.folder_id ?? props.folderId` (move targets, the Sent/Drafts
 * "به:" identity, etc.) falls back correctly to each result's own real
 * folder instead of this sentinel.
 */
export const SEARCH_ID = 'search'

export const useMessagesStore = defineStore('messages', {
  state: () => ({
    items: [],
    meta: null,
    folderId: null,
    listLoading: false,
    syncing: false,
    listError: null,
    syncError: null,

    searchQuery: '',
    searchAccountId: null,

    selected: null,
    detailLoading: false,
    detailError: null,

    flagUpdatingId: null,
    deletingId: null,
    deleteError: null,
    emptyingTrash: false,
    emptyTrashError: null,
    movingId: null,
    moveError: null,
  }),

  actions: {
    async fetchList(folderId, page = 1) {
      this.folderId = folderId
      this.listLoading = true
      this.listError = null

      try {
        const data = await api.get(`/folders/${folderId}/messages?page=${page}`)
        this.items = data.data
        this.meta = data.meta
      } catch (error) {
        this.listError = error
      } finally {
        this.listLoading = false
      }
    },

    /**
     * The virtual "Starred" view (CLAUDE.md §8) — flagged messages across
     * every folder in the account, answered from the local DB only (no live
     * IMAP call, `is_flagged` is already kept in sync per message).
     */
    async fetchStarred(accountId, page = 1) {
      this.folderId = STARRED_ID
      this.listLoading = true
      this.listError = null

      try {
        const data = await api.get(`/email-accounts/${accountId}/starred-messages?page=${page}`)
        this.items = data.data
        this.meta = data.meta
      } catch (error) {
        this.listError = error
      } finally {
        this.listLoading = false
      }
    },

    /**
     * Free-text search across every folder in the account — local-DB only,
     * same category as `fetchStarred()`. Matches subject, sender,
     * recipients, and the already-stored plain-text body.
     */
    async search(accountId, query, page = 1) {
      this.folderId = SEARCH_ID
      this.searchQuery = query
      this.searchAccountId = accountId
      this.listLoading = true
      this.listError = null

      try {
        const data = await api.get(`/email-accounts/${accountId}/search-messages?q=${encodeURIComponent(query)}&page=${page}`)
        this.items = data.data
        this.meta = data.meta
      } catch (error) {
        this.listError = error
      } finally {
        this.listLoading = false
      }
    },

    /**
     * Pull new mail from the live IMAP server for this folder, then refresh
     * the locally-stored list (FR-2.7 + FR-3.1).
     */
    async sync(folderId) {
      this.syncing = true
      this.syncError = null

      try {
        await api.post(`/folders/${folderId}/sync`)
        await this.fetchList(folderId, 1)
        useFoldersStore().markSynced(folderId)
      } catch (error) {
        this.syncError = error
        throw error
      } finally {
        this.syncing = false
      }
    },

    async openMessage(messageId) {
      this.detailLoading = true
      this.detailError = null
      this.selected = null

      try {
        const message = await api.get(`/messages/${messageId}`)
        this.selected = message

        if (!message.is_read) {
          await this.setFlags(messageId, { is_read: true })
        }
      } catch (error) {
        this.detailError = error
      } finally {
        this.detailLoading = false
      }
    },

    closeMessage() {
      this.selected = null
      this.detailError = null
    },

    /**
     * Optimistic — a real IMAP round-trip is a couple of seconds, and a
     * star/read toggle needs to feel instant. Applied immediately, rolled
     * back only if the server call actually fails. In the Starred view,
     * unstarring a message also removes it from the (filtered) list right
     * away, since it would no longer belong there.
     */
    async setFlags(messageId, flags) {
      this.flagUpdatingId = messageId

      const folders = useFoldersStore()
      const target = this.items.find((item) => item.id === messageId)
      const previousItem = target ? { ...target } : null
      const previousSelected = this.selected?.id === messageId ? { ...this.selected } : null
      const folderId = target?.folder_id ?? this.folderId
      const removeIfUnstarred = this.folderId === STARRED_ID && flags.is_flagged === false

      const applyLocally = (values) => {
        if (removeIfUnstarred) {
          this.items = this.items.filter((item) => item.id !== messageId)
        } else {
          this.items = this.items.map((item) => (item.id === messageId ? { ...item, ...values } : item))
        }
        if (this.selected?.id === messageId) {
          this.selected = { ...this.selected, ...values }
        }
      }

      applyLocally(flags)
      if ('is_read' in flags && previousItem && previousItem.is_read !== flags.is_read) {
        folders.adjustUnreadCount(folderId, flags.is_read ? -1 : 1)
      }

      try {
        const updated = await api.patch(`/messages/${messageId}/flags`, flags)
        if (!removeIfUnstarred) {
          applyLocally(updated)
        }
        return updated
      } catch (error) {
        if (previousItem) {
          this.items = removeIfUnstarred
            ? [...this.items, previousItem].sort((a, b) => new Date(b.date) - new Date(a.date))
            : this.items.map((item) => (item.id === messageId ? previousItem : item))
        }
        if (previousSelected) {
          this.selected = previousSelected
        }
        if ('is_read' in flags && previousItem && previousItem.is_read !== flags.is_read) {
          folders.adjustUnreadCount(folderId, flags.is_read ? 1 : -1)
        }
        throw error
      } finally {
        this.flagUpdatingId = null
      }
    },

    /**
     * Context-aware, same as the backend endpoint it calls: from anywhere
     * except Trash this moves the message to Trash (recoverable); from
     * inside Trash it's permanent. Optimistic, same reasoning as setFlags.
     */
    async deleteMessage(messageId) {
      this.deletingId = messageId
      this.deleteError = null

      const folders = useFoldersStore()
      const target = this.items.find((item) => item.id === messageId)
      const wasUnread = target ? !target.is_read : false
      const sourceFolderId = target?.folder_id ?? this.folderId
      const sourceFolder = folders.items.find((folder) => folder.id === sourceFolderId)
      const wasInTrash = sourceFolder?.type === 'trash'
      const previousItems = this.items
      const previousSelected = this.selected

      const adjustCounts = (sign) => {
        if (!wasUnread || !sourceFolderId) return
        folders.adjustUnreadCount(sourceFolderId, sign * -1)
        if (!wasInTrash) {
          const trash = folders.items.find((folder) => folder.type === 'trash')
          if (trash) folders.adjustUnreadCount(trash.id, sign * 1)
        }
      }

      this.items = this.items.filter((item) => item.id !== messageId)
      if (this.selected?.id === messageId) {
        this.closeMessage()
      }
      adjustCounts(1)

      try {
        await api.delete(`/messages/${messageId}`)
      } catch (error) {
        this.items = previousItems
        this.selected = previousSelected
        adjustCounts(-1)
        this.deleteError = error
        throw error
      } finally {
        this.deletingId = null
      }
    },

    /**
     * Move a message to another folder (drag-and-drop or a "Move to" menu).
     * Optimistic, same reasoning as setFlags/deleteMessage — removed from
     * the current (single-folder) list right away; a virtual view like
     * Starred keeps showing it (still flagged, just in a different folder)
     * so nothing needs to change there.
     */
    async moveMessage(messageId, targetFolderId) {
      this.movingId = messageId
      this.moveError = null

      const folders = useFoldersStore()
      const target = this.items.find((item) => item.id === messageId)
      const wasUnread = target ? !target.is_read : false
      const sourceFolderId = target?.folder_id ?? this.folderId
      const previousItems = this.items
      const previousSelected = this.selected
      const removeFromList = this.folderId !== STARRED_ID && this.folderId !== SEARCH_ID

      const adjustCounts = (sign) => {
        if (!wasUnread) return
        if (sourceFolderId) folders.adjustUnreadCount(sourceFolderId, sign * -1)
        folders.adjustUnreadCount(targetFolderId, sign * 1)
      }

      if (removeFromList) {
        this.items = this.items.filter((item) => item.id !== messageId)
        if (this.selected?.id === messageId) {
          this.closeMessage()
        }
      }
      adjustCounts(1)

      try {
        await api.patch(`/messages/${messageId}/move`, { folder_id: targetFolderId })
      } catch (error) {
        this.items = previousItems
        this.selected = previousSelected
        adjustCounts(-1)
        this.moveError = error
        throw error
      } finally {
        this.movingId = null
      }
    },

    /**
     * Permanently delete every message in the Trash folder (FR request:
     * "empty trash" button).
     */
    async emptyTrash(folderId) {
      this.emptyingTrash = true
      this.emptyTrashError = null

      try {
        await api.delete(`/folders/${folderId}/messages`)

        if (this.folderId === folderId) {
          this.items = []
          this.meta = null
        }

        useFoldersStore().setUnreadCount(folderId, 0)
      } catch (error) {
        this.emptyTrashError = error
        throw error
      } finally {
        this.emptyingTrash = false
      }
    },

    /**
     * Removes a message from the currently-displayed list/selection without
     * an API call — for cases where the backend has already made the change
     * as a side effect of a different request (e.g. a sent draft being
     * permanently deleted as part of the send itself, §8 item 65).
     */
    removeMessageLocally(messageId) {
      this.items = this.items.filter((item) => item.id !== messageId)
      if (this.selected?.id === messageId) {
        this.closeMessage()
      }
    },

    reset() {
      this.items = []
      this.meta = null
      this.folderId = null
      this.selected = null
      this.searchQuery = ''
      this.searchAccountId = null
    },
  },
})
