import type { User } from "@/lib/types";

const API_BASE = "/api/v1";

function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("auth_token");
}

export function setToken(token: string): void {
  localStorage.setItem("auth_token", token);
}

export function clearToken(): void {
  localStorage.removeItem("auth_token");
}

async function request<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const token = getToken();
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...options.headers as Record<string, string>,
  };

  if (token) {
    headers["Authorization"] = `Bearer ${token}`;
  }

  // Let the browser build the multipart Content-Type, boundary included.
  if (options.body instanceof FormData) {
    delete headers["Content-Type"];
  }

  const res = await fetch(`${API_BASE}${path}`, { ...options, headers });

  if (res.status === 204) return undefined as T;

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

export function errorMessage(err: unknown, fallback: string): string {
  if (err instanceof Error && err.message !== "") return err.message;
  return bodyMessage(err) ?? fallback;
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
    api.post<{ user: User; token: string }>("/auth/login", { email, password }),
  register: (data: { first_name: string; last_name?: string; email: string; password: string; password_confirmation: string }) =>
    api.post<User>("/auth/register", data),
  logout: () => api.post("/auth/logout"),
  me: () => api.get<User>("/auth/me"),
};
