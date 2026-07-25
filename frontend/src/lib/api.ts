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

  const res = await fetch(`${API_BASE}${path}`, { ...options, headers });

  if (res.status === 204) return undefined as T;

  if (!res.ok) {
    const error = await res.json().catch(() => ({ message: "Request failed" }));
    throw error;
  }

  const json = await res.json();

  // The API disables resource wrapping (JsonResource::withoutWrapping), so list
  // endpoints return a bare array while the pages expect { data: [...] }.
  return Array.isArray(json) ? ({ data: json } as T) : json;
}

export const api = {
  get: <T>(path: string) => request<T>(path, { method: "GET" }),
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
