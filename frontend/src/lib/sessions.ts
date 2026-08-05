import { api } from "./api";

/**
 * The devices signed in to this account.
 *
 * Platform-owned, not workspace-owned: a student studying with three teachers
 * has one list, not three. Which machine someone studies on is also not academic
 * information, so no teacher can read this list at all — the server enforces
 * that, and there is no teacher-side screen here to match.
 */

export type AuthSession = {
  uuid: string;
  status: string;
  is_current: boolean;
  device: { uuid: string; label: string };
  ended_reason: string | null;
  last_active_at: string | null;
  ended_at: string | null;
  created_at: string;
};

export const sessions = {
  list: () => api.get<{ data: AuthSession[] }>("/auth/sessions"),

  /**
   * End one. Ending the current session is allowed and signs this browser out —
   * the token is deleted server-side, so the next request 401s and the handler
   * in lib/api.ts takes over.
   */
  end: (uuid: string) => api.delete<void>(`/auth/sessions/${uuid}`),
};
