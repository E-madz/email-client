const BASE_URL = import.meta.env.VITE_API_BASE_URL

const TOKEN_STORAGE_KEY = 'email_client_token'

export class ApiError extends Error {
  constructor(status, message, errors = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

let unauthorizedHandler = null

/**
 * Register a callback invoked whenever the API responds 401 (an expired or
 * invalid token). The auth store uses this to force a clean logout instead
 * of leaving the app in a half-authenticated state.
 */
export function onUnauthorized(handler) {
  unauthorizedHandler = handler
}

export function getToken() {
  return localStorage.getItem(TOKEN_STORAGE_KEY)
}

export function setToken(token) {
  if (token) {
    localStorage.setItem(TOKEN_STORAGE_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_STORAGE_KEY)
  }
}

async function request(path, { method = 'GET', body, signal, isFormData = false } = {}) {
  const headers = {
    Accept: 'application/json',
  }

  const token = getToken()
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  let requestBody
  if (body !== undefined) {
    if (isFormData) {
      // No Content-Type here on purpose — the browser sets
      // multipart/form-data with the correct boundary itself.
      requestBody = body
    } else {
      headers['Content-Type'] = 'application/json'
      requestBody = JSON.stringify(body)
    }
  }

  let response
  try {
    response = await fetch(`${BASE_URL}${path}`, {
      method,
      headers,
      body: requestBody,
      signal,
    })
  } catch {
    throw new ApiError(0, 'اتصال به سرور برقرار نشد. اتصال اینترنت یا در دسترس بودن سرور را بررسی کنید.')
  }

  if (response.status === 204) {
    return null
  }

  const contentType = response.headers.get('content-type') ?? ''
  const data = contentType.includes('application/json') ? await response.json() : null

  if (!response.ok) {
    if (response.status === 401 && unauthorizedHandler) {
      unauthorizedHandler()
    }

    const message = data?.message ?? 'خطای غیرمنتظره‌ای رخ داد.'
    throw new ApiError(response.status, message, data?.errors ?? null)
  }

  return data
}

/**
 * Downloads use their own path — the response body is a file, not JSON, so
 * it can't go through request()'s JSON parsing. Auth still needs the same
 * Bearer header a plain <a href="..."> could never attach.
 */
async function downloadBlob(path) {
  const headers = {}
  const token = getToken()
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  let response
  try {
    response = await fetch(`${BASE_URL}${path}`, { headers })
  } catch {
    throw new ApiError(0, 'اتصال به سرور برقرار نشد. اتصال اینترنت یا در دسترس بودن سرور را بررسی کنید.')
  }

  if (!response.ok) {
    if (response.status === 401 && unauthorizedHandler) {
      unauthorizedHandler()
    }

    let message = 'دانلود پیوست با خطا مواجه شد.'
    try {
      const data = await response.json()
      message = data?.message ?? message
    } catch {
      // Response wasn't JSON (e.g. a plain-text abort message) — keep the default.
    }
    throw new ApiError(response.status, message)
  }

  return response.blob()
}

export const api = {
  get: (path, options) => request(path, { ...options, method: 'GET' }),
  post: (path, body, options) => request(path, { ...options, method: 'POST', body }),
  put: (path, body, options) => request(path, { ...options, method: 'PUT', body }),
  patch: (path, body, options) => request(path, { ...options, method: 'PATCH', body }),
  // body is optional — most DELETE calls don't need one, but the bulk
  // delete endpoint takes a message_ids list in the body (DELETE with a
  // body is unusual but valid HTTP, and Laravel reads it like any other).
  delete: (path, body, options) => request(path, { ...options, method: 'DELETE', body }),
  postForm: (path, formData, options) => request(path, { ...options, method: 'POST', body: formData, isFormData: true }),
  // PHP never parses a multipart body on a real PUT request, so this rides
  // Laravel's standard `_method` override on a POST instead (Illuminate\Http\Request
  // enables this by default) — needed for updating a draft's attachments.
  putForm: (path, formData, options) => {
    formData.append('_method', 'PUT')
    return request(path, { ...options, method: 'POST', body: formData, isFormData: true })
  },
  downloadBlob,
}
