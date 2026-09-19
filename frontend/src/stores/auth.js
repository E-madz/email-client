import { defineStore } from 'pinia'
import { api, getToken, setToken, onUnauthorized, ApiError } from '../lib/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: getToken(),
    booting: true,
    submitting: false,
    error: null,
  }),

  getters: {
    isAuthenticated: (state) => Boolean(state.token),
  },

  actions: {
    /**
     * Called once on app boot to check whether a saved token is still valid.
     */
    async init() {
      if (!this.token) {
        this.booting = false
        return
      }

      try {
        this.user = await api.get('/user')
      } catch {
        this.token = null
        setToken(null)
      } finally {
        this.booting = false
      }
    },

    async login({ username, password }) {
      this.submitting = true
      this.error = null

      try {
        const data = await api.post('/login', { username, password })
        this.user = data.user
        this.token = data.token
        setToken(data.token)
      } catch (error) {
        this.error = error instanceof ApiError ? error : null
        throw error
      } finally {
        this.submitting = false
      }
    },

    async register(payload) {
      this.submitting = true
      this.error = null

      try {
        const data = await api.post('/register', payload)
        this.user = data.user
        this.token = data.token
        setToken(data.token)
      } catch (error) {
        this.error = error instanceof ApiError ? error : null
        throw error
      } finally {
        this.submitting = false
      }
    },

    async updateProfile({ name, username }) {
      this.submitting = true
      this.error = null

      try {
        this.user = await api.put('/profile', { name, username })
      } catch (error) {
        this.error = error instanceof ApiError ? error : null
        throw error
      } finally {
        this.submitting = false
      }
    },

    async updatePassword({ current_password, password, password_confirmation }) {
      this.submitting = true
      this.error = null

      try {
        await api.put('/profile/password', { current_password, password, password_confirmation })
      } catch (error) {
        this.error = error instanceof ApiError ? error : null
        throw error
      } finally {
        this.submitting = false
      }
    },

    async logout() {
      try {
        await api.post('/logout')
      } catch {
        // Best-effort: log out locally regardless of whether the server call succeeded.
      }

      this.forceLogout()
    },

    forceLogout() {
      this.user = null
      this.token = null
      setToken(null)
    },
  },
})

// Any 401 from the API (expired/invalid token) forces a clean local logout,
// wired once here rather than in every store that calls the API.
onUnauthorized(() => {
  useAuthStore().forceLogout()
})
