import { api } from "./api";

/**
 * Two-factor enrolment, both halves.
 *
 * The state endpoint never returns the secret and never returns the recovery
 * codes — they are stored hashed server-side, so a "show them again" call could
 * not be built. `confirm` and `regenerateRecoveryCodes` are the only two places
 * codes exist in readable form, which is why the screen has to insist the user
 * saves them there and then.
 */

export type TwoFactorState = {
  enabled: boolean;
  confirmed_at: string | null;
  required_at: string | null;
  recovery_codes_remaining: number;
};

export type TwoFactorChallengeResult = {
  user: import("@/lib/types").User;
  token: string;
  session_uuid: string;
};

export const twoFactor = {
  state: () => api.get<TwoFactorState>("/auth/2fa"),

  /** Returns the `otpauth://` URI the authenticator app scans or accepts typed. */
  setup: (currentPassword: string) =>
    api.post<{ otpauth_uri: string }>("/auth/2fa/setup", { current_password: currentPassword }),

  confirm: (code: string) =>
    api.post<{ recovery_codes: string[] }>("/auth/2fa/confirm", { code }),

  disable: (currentPassword: string, code: string) =>
    api.delete<void>("/auth/2fa", { current_password: currentPassword, code }),

  regenerateRecoveryCodes: () =>
    api.post<{ recovery_codes: string[] }>("/auth/2fa/recovery-codes"),

  /** The second half of signing in. No token exists yet, so none is sent. */
  challenge: (challenge: string, credential: { code?: string; recovery_code?: string }) =>
    api.post<TwoFactorChallengeResult>("/auth/2fa/challenge", { challenge, ...credential }),
};
