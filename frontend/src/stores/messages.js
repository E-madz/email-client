import { defineStore } from 'pinia'
import { api } from '../lib/api'
import { useFoldersStore } from './folders'

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
     * Pull new mail from the live IMAP server for this folder, then refresh
     * the locally-stored list (FR-2.7 + FR-3.1).
     */
    async sync(folderId) {
      this.syncing = true
      this.syncError = null

      try {
        await api.post(`/folders/${folderId}/sync`)
        await this.fetchList(folderId, 1)
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

    async setFlags(messageId, flags) {
      this.flagUpdatingId = messageId

      try {
        const updated = await api.patch(`/messages/${messageId}/flags`, flags)

        const wasRead = this.items.find((item) => item.id === messageId)?.is_read
        this.items = this.items.map((item) => (item.id === messageId ? { ...item, ...updated } : item))

        if (this.selected?.id === messageId) {
          this.selected = { ...this.selected, ...updated }
        }

        if ('is_read' in flags && wasRead !== undefined && wasRead !== updated.is_read) {
          useFoldersStore().adjustUnreadCount(this.folderId, updated.is_read ? -1 : 1)
        }

        return updated
      } finally {
        this.flagUpdatingId = null
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
