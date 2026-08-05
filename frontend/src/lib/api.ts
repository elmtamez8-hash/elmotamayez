import { userMessage, UNKNOWN_MESSAGE } from "./errors";

import type {
  ParentRegistration,
  StudentRegistration,
  TeacherApplication,
  TeacherRegistration,
  User,
} from "@/lib/types";

const API_BASE = "/api/v1";

const TOKEN_KEY = "auth_token";
const SESSION_KEY = "auth_session";
const DEVICE_KEY = "device_id";

function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem(TOKEN_KEY);
}

/**
 * A stable id for this browser, kept only here.
 *
 * The device limit counts devices, and the server derives one from this header
 * plus the user agent. Without it two people sharing an account from the same
 * phone model look like one machine — which is the one case the limit exists
 * for. It identifies a browser, not a person: it is random, per-origin, and
 * survives nothing but this profile.
 */
function deviceId(): string | null {
  if (typeof window === "undefined") return null;

  let id = localStorage.getItem(DEVICE_KEY);

  if (id === null) {
    // randomUUID needs a secure context; plain http on a LAN is a normal way to
    // test on a real phone, so fall back rather than throw there.
    id =
      typeof crypto.randomUUID === "function"
        ? crypto.randomUUID()
        : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;

    localStorage.setItem(DEVICE_KEY, id);
  }

  return id;
}

/** Whether a session exists at all. Public pages need this much and no more —
 * they are not wrapped in AuthProvider, and pulling it in for one form would put
 * an /auth/me request on every crawlable page. */
export function hasAuthToken(): boolean {
  return getToken() !== null;
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(SESSION_KEY);
}

/**
 * Remembered from sign-in so the client can ask *why* it was signed out.
 *
 * The question is only ever asked once the token is gone, so the uuid has to be
 * held from before that — there is nothing left afterwards to look it up with.
 */
export function setSessionUuid(uuid: string): void {
  localStorage.setItem(SESSION_KEY, uuid);
}

async function request<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const token = getToken();
  const device = deviceId();
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...options.headers as Record<string, string>,
  };

  if (token) {
    headers["Authorization"] = `Bearer ${token}`;
  }

  if (device) {
    headers["X-Device-Id"] = device;
  }

  // Let the browser build the multipart Content-Type, boundary included.
  if (options.body instanceof FormData) {
    delete headers["Content-Type"];
  }

  const res = await fetch(`${API_BASE}${path}`, { ...options, headers });

  if (res.status === 204) return undefined as T;

  // Held a token and the server refuses it: this session was ended somewhere
  // else — another device signed in, a password changed, or the session was
  // ended from the devices list. Nothing the user did here caused it, so they
  // are told which of those it was rather than dropped on a blank login form.
  if (res.status === 401 && token !== null && !path.startsWith("/auth/login")) {
    await goToLoginWithReason();
  }

  if (!res.ok) {
    const body: unknown = await res.json().catch(() => null);
    throw new ApiError(bodyMessage(body) ?? `Request failed (${res.status})`, res.status, body);
  }

  const json = await res.json();

  // The API disables resource wrapping (JsonResource::withoutWrapping), so list
  // endpoints return a bare array while the pages expect { data: [...] }.
  return Array.isArray(json) ? ({ data: json } as T) : json;
}

/**
 * Clear the dead token and send the user to sign in, saying why.
 *
 * The reason comes from an unauthenticated endpoint on purpose: by the time it
 * is asked, there is no token left to authenticate with. It answers two fields
 * and nothing else, so this leaks no more than the uuid the client already had.
 */
async function goToLoginWithReason(): Promise<void> {
  const session = localStorage.getItem(SESSION_KEY);
  clearToken();

  let reason: string | null = null;

  if (session !== null) {
    reason = await fetch(`${API_BASE}/auth/sessions/${session}/end-reason`, {
      headers: { Accept: "application/json" },
    })
      .then((res) => (res.ok ? (res.json() as Promise<{ reason: string | null }>) : null))
      .then((body) => body?.reason ?? null)
      // A reason we could not fetch is not worth failing the sign-out over.
      .catch(() => null);
  }

  if (window.location.pathname === "/login") return;

  window.location.replace(reason === null ? "/login" : `/login?ended=${reason}`);
}

/**
 * A failed request. Always an Error subclass — throwing the raw JSON body made
 * React's overlay render "[object Object]" with no clue what broke.
 */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly body: unknown,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

function bodyMessage(body: unknown): string | null {
  if (typeof body === "object" && body !== null && "message" in body) {
    const message = (body as { message: unknown }).message;
    if (typeof message === "string" && message !== "") return message;
  }
  return null;
}

/**
 * Laravel's 422 body, flattened to one message per field.
 *
 * Only the first message per field is kept: a form shows one line under each
 * input, and stacking three there turns a fixable mistake into a wall of text.
 */
export function fieldErrors(err: unknown): Record<string, string> {
  if (!(err instanceof ApiError) || err.status !== 422) return {};

  const body = err.body;
  if (typeof body !== "object" || body === null || !("errors" in body)) return {};

  const errors = (body as { errors: unknown }).errors;
  if (typeof errors !== "object" || errors === null) return {};

  const flat: Record<string, string> = {};
  for (const [field, messages] of Object.entries(errors)) {
    if (Array.isArray(messages) && typeof messages[0] === "string") {
      flat[field] = messages[0];
    }
  }
  return flat;
}

/**
 * A user-facing message for a failed request.
 *
 * Only 422 bodies are echoed: those are validation messages, translated at their
 * source in `backend/lang/ar/`. Every other status goes through the Arabic table
 * in `errors.ts` — the framework's own strings ("Too Many Attempts.", "Server
 * Error") are English and reach the screen otherwise, which FR-017 forbids.
 *
 * `fallback` is used only when the status maps to nothing more specific.
 */
export function errorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError && err.status === 422 && err.message !== "") {
    return err.message;
  }

  const mapped = userMessage(err);
  return mapped === UNKNOWN_MESSAGE ? fallback : mapped;
}

export const api = {
  get: <T>(path: string) => request<T>(path, { method: "GET" }),
  upload: <T>(path: string, form: FormData) =>
    request<T>(path, { method: "POST", body: form }),
  post: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: "POST", body: data ? JSON.stringify(data) : undefined }),
  put: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: "PUT", body: data ? JSON.stringify(data) : undefined }),
  patch: <T>(path: string, data?: unknown) =>
    request<T>(path, { method: "PATCH", body: data ? JSON.stringify(data) : undefined }),
  delete: <T>(path: string) => request<T>(path, { method: "DELETE" }),
};

export const auth = {
  login: (email: string, password: string) =>
    api.post<{ user: User; token: string; session_uuid: string }>("/auth/login", {
      email,
      password,
    }),
  register: (data: { first_name: string; last_name?: string; email: string; password: string; password_confirmation: string }) =>
    api.post<User>("/auth/register", data),
  registerStudent: (data: StudentRegistration, idempotencyKey: string) =>
    request<{ user: User; token: string; session_uuid: string }>("/auth/register/student", {
      method: "POST",
      body: JSON.stringify(data),
      headers: { "Idempotency-Key": idempotencyKey },
    }),
  registerParent: (data: ParentRegistration, idempotencyKey: string) =>
    request<{ user: User; token: string; session_uuid: string }>("/auth/register/parent", {
      method: "POST",
      body: JSON.stringify(data),
      headers: { "Idempotency-Key": idempotencyKey },
    }),
  registerTeacher: (data: TeacherRegistration, idempotencyKey: string) =>
    request<{ application: TeacherApplication; token: string | null }>(
      "/auth/register/teacher/step-1",
      {
        method: "POST",
        body: JSON.stringify(data),
        headers: { "Idempotency-Key": idempotencyKey },
      },
    ),
  logout: () => api.post("/auth/logout"),
  me: () => api.get<User>("/auth/me"),
};
