import { defineStore } from 'pinia'
import { api } from '../lib/api'

const ACTIVE_ACCOUNT_STORAGE_KEY = 'email_client_active_account_id'

export const useAccountsStore = defineStore('accounts', {
  state: () => ({
    items: [],
    activeId: Number(localStorage.getItem(ACTIVE_ACCOUNT_STORAGE_KEY)) || null,
    loading: false,
    error: null,
  }),

  getters: {
    active: (state) => state.items.find((account) => account.id === state.activeId) ?? null,
  },

  actions: {
    async fetch() {
      this.loading = true
      this.error = null

      try {
        this.items = await api.get('/email-accounts')

        if (!this.activeId || !this.items.some((account) => account.id === this.activeId)) {
          this.setActive(this.items[0]?.id ?? null)
        }
      } catch (error) {
        this.error = error
      } finally {
        this.loading = false
      }
    },

    setActive(id) {
      this.activeId = id

      if (id) {
        localStorage.setItem(ACTIVE_ACCOUNT_STORAGE_KEY, String(id))
      } else {
        localStorage.removeItem(ACTIVE_ACCOUNT_STORAGE_KEY)
      }
    },

    /**
     * @returns the newly connected account on success; throws ApiError on failure
     * (422 = wrong credentials or duplicate address, 503 = mail server unreachable).
     */
    async add({ email, password }) {
      const account = await api.post('/email-accounts', { email, password })
      this.items.push(account)
      this.setActive(account.id)

      return account
    },

    /**
     * Re-verify an account after it was flagged needs_reauth with a new password.
     */
    async reauth(id, password) {
      const account = await api.put(`/email-accounts/${id}`, { password })
      const index = this.items.findIndex((item) => item.id === id)
      if (index !== -1) {
        this.items[index] = account
      }

      return account
    },

    async remove(id) {
      await api.delete(`/email-accounts/${id}`)
      this.items = this.items.filter((account) => account.id !== id)

      if (this.activeId === id) {
        this.setActive(this.items[0]?.id ?? null)
      }
    },
  },
})
