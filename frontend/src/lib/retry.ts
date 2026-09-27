import { ApiError } from "./api";

/**
 * Whether a failed request is worth asking again WITHOUT the person pressing
 * anything.
 *
 * ⛔ A 4xx IS AN ANSWER, NOT A HICCUP. On 2026-09-26 one device sent 228
 * `POST /class-sessions/{uuid}/join` in two hours, every one of them a 403: the
 * room page knocked again every twenty seconds for as long as it stayed open,
 * and asking the same question again cannot change a refusal. Only three things
 * can: the network came back, the server recovered (5xx), or the limiter's
 * window passed (429). Everything else — 401/403/404/409/410/422, and any error
 * this function does not recognise — is final until a person acts.
 *
 * `TypeError` is what `fetch()` rejects with when the request never reached a
 * server at all (offline, DNS, a dropped connection).
 */
export function isTransientFailure(err: unknown): boolean {
  if (err instanceof ApiError) return err.status >= 500 || err.status === 429;

  return err instanceof TypeError;
}

/** Total tries for a transient failure, the first one included. */
export const MAX_AUTOMATIC_TRIES = 5;

const BASE_DELAY_MS = 2_000;
const MAX_DELAY_MS = 30_000;

/**
 * How long to wait before try number `failedTries + 1`: 2s, 4s, 8s, 16s, capped
 * at 30s. Deterministic on purpose — a test can advance the clock by exactly the
 * schedule, and one tab has nobody to de-synchronise from.
 */
export function backoffDelay(failedTries: number): number {
  return Math.min(BASE_DELAY_MS * 2 ** Math.max(0, failedTries - 1), MAX_DELAY_MS);
}
