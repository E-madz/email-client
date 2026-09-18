import { defineStore } from 'pinia'
import { api } from '../lib/api'
import { useFoldersStore, STARRED_ID } from './folders'

export const useMessagesStore = defineStore('messages', {
  state: () => ({
    items: [],
    meta: null,
    folderId: null,
    listLoading: false,
    syncing: false,
    listError: null,
    syncError: null,

    selected: null,
    detailLoading: false,
    detailError: null,

    flagUpdatingId: null,
    deletingId: null,
    deleteError: null,
    emptyingTrash: false,
    emptyTrashError: null,
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

    reset() {
      this.items = []
      this.meta = null
      this.folderId = null
      this.selected = null
    },
  },
})
