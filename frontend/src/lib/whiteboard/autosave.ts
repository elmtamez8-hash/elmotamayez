import { ApiError } from "@/lib/api";
import { draftKey, type DraftStore } from "@/lib/whiteboard/draft-store";

/**
 * The autosave of one open board (spec 039 · US2, R-08). Pure logic — every
 * dependency is injected (the PUT, the draft store, the idle scheduler), so the
 * tests drive it with fake timers and no browser.
 *
 * Two layers, and the FIRST is the guarantee:
 *  1. the page's draft on this device, written when the browser is idle (≤ 2 s
 *     after the stroke), only while this tab holds the lock;
 *  2. the server, 1.5 s after drawing stops — one PUT in flight per page, the
 *     latest scene waiting behind it.
 *
 * ⚠️ A RETRY RESENDS THE SAME `(client_rev, scene)`. The server treats a repeated
 * `(tab, client_rev)` whose version moved one step as «already applied» and
 * answers 200 WITHOUT storing what came — so a NEWER scene sent under an old rev
 * after a lost answer would be acknowledged and dropped. Newer edits wait for the
 * retry to land and then go out under a new rev.
 *
 * ⚠️ NO AUTOMATIC VERSION BUMP. A `version_conflict` stops that page and asks the
 * teacher (FR-012); retrying with the server's version would overwrite the other
 * copy silently.
 */

export const SERVER_DEBOUNCE_MS = 1500;
export const IDLE_TIMEOUT_MS = 2000;
/** `keepalive` bodies are capped by the browser at 64 KB for all in-flight requests. */
export const KEEPALIVE_LIMIT = 64 * 1024;
const MAX_BACKOFF_MS = 30_000;

export type SaveState = "saved" | "saving" | "offline" | "failed" | "unprotected";

export interface ScenePut {
  tab: string;
  version: number;
  client_rev: number;
  scene: string;
}

export interface AutosaveDeps {
  boardUuid: string;
  userUuid: string;
  tab: string;
  put(page: string, body: ScenePut): Promise<{ version: number; client_rev: number }>;
  putKeepalive(page: string, body: ScenePut): void;
  drafts: DraftStore;
  /** Run `fn` when the browser is idle, at most `IDLE_TIMEOUT_MS` later. Returns a cancel. */
  idle(fn: () => void): () => void;
  onState(state: SaveState): void;
  onConflict(page: string, server: { version: number; scene: string }): void;
  onLockLost(): void;
  /** A refusal no retry can fix: a 422 code, or `workspace_changed`. */
  onRefused(code: string): void;
}

interface PageSlot {
  version: number;
  hash: number;
  /** The hash as tracked, worked out when first compared — reading it restores the page (PageStore). */
  lazyHash?: () => number;
  build: (() => string) | null;
  dirty: boolean;
  /** The hash the in-flight (or retrying) request carries. */
  sentHash: number | null;
  inFlight: boolean;
  retry: { rev: number; scene: string; hash: number } | null;
  stopped: boolean;
  debounce: ReturnType<typeof setTimeout> | null;
  cancelIdle: (() => void) | null;
}

/**
 * One serialisation per scene: the draft and the PUT share it (a large page is
 * megabytes of JSON, and it was stringified twice).
 */
function once(build: () => string): () => string {
  let scene: string | undefined;
  return () => (scene ??= build());
}

function codeOf(error: unknown): string | null {
  if (!(error instanceof ApiError)) return null;
  const body = error.body as { code?: unknown } | null;
  return typeof body?.code === "string" ? body.code : null;
}

export function createAutosave(deps: AutosaveDeps) {
  const pages = new Map<string, PageSlot>();
  let holding = false;
  let ended = false;
  let rev = 0;
  let unprotected = false;
  let failed = false;
  let backoff = 1000;
  let backoffTimer: ReturnType<typeof setTimeout> | null = null;
  let idleWaiters: (() => void)[] = [];

  const key = (page: string) => draftKey(deps.boardUuid, page, deps.userUuid);

  function report(): void {
    const all = [...pages.values()];
    if (failed) return deps.onState("failed");
    if (all.some((p) => p.retry !== null)) return deps.onState("offline");
    if (all.some((p) => p.dirty || p.inFlight)) return deps.onState("saving");
    deps.onState(unprotected ? "unprotected" : "saved");
  }

  function settleIfIdle(): void {
    if ([...pages.values()].some((p) => p.inFlight)) return;
    const waiters = idleWaiters;
    idleWaiters = [];
    waiters.forEach((resolve) => resolve());
  }

  async function writeDraft(page: string, slot: PageSlot): Promise<void> {
    // ⚠️ Asked when the write RUNS, not when it was scheduled: an idle callback can
    // fire after the server already acknowledged this scene, and writing then
    // leaves a «not saved» draft of saved work that asks to be restored on reopen.
    if (!slot.build || !slot.dirty) return;
    const result = await deps.drafts.put(key(page), { scene: slot.build(), ackedVersion: slot.version, rev, dirty: true });
    if (result === "unavailable" && !unprotected) {
      unprotected = true;
      report();
    }
  }

  /** Cancel every waiting timer. */
  function quiet(): void {
    for (const slot of pages.values()) {
      if (slot.debounce) clearTimeout(slot.debounce);
      slot.cancelIdle?.();
      slot.debounce = null;
      slot.cancelIdle = null;
    }
    if (backoffTimer) clearTimeout(backoffTimer);
    backoffTimer = null;
  }

  /** For good: a refusal no retry can fix, or the board closing. */
  function stopAll(): void {
    ended = true;
    quiet();
  }

  function send(page: string): void {
    const slot = pages.get(page);
    if (!slot || ended || slot.stopped || !holding || slot.inFlight) return;
    if (!slot.retry && (!slot.dirty || !slot.build)) return;

    const payload = slot.retry ?? { rev: ++rev, scene: slot.build!(), hash: slot.hash };
    slot.inFlight = true;
    slot.sentHash = payload.hash;
    report();

    deps
      .put(page, { tab: deps.tab, version: slot.version, client_rev: payload.rev, scene: payload.scene })
      .then((answer) => {
        slot.inFlight = false;
        slot.retry = null;
        slot.version = answer.version;
        backoff = 1000;
        if (slot.hash === payload.hash) {
          slot.dirty = false;
          slot.build = null; // saved: let go of the scene (and the page's elements)
          void deps.drafts.remove(key(page));
        } else {
          send(page); // the latest scene, waiting behind this one
        }
        report();
        settleIfIdle();
      })
      .catch((error: unknown) => {
        slot.inFlight = false;
        const code = codeOf(error);
        const status = error instanceof ApiError ? error.status : 0;

        if (code === "version_conflict") {
          slot.stopped = true;
          slot.retry = null;
          const body = (error as ApiError).body as { version: number; scene: string };
          deps.onConflict(page, { version: body.version, scene: body.scene });
        } else if (code === "lock_lost") {
          // The draft is the copy that survives; the board becomes read-only.
          // PAUSED, not ended: if this tab gets the lock back, saving resumes
          // (an `ended` flag here once left a re-editable board saving nothing).
          for (const [id, s] of pages) if (s.dirty) void writeDraft(id, s);
          quiet();
          holding = false;
          deps.onLockLost();
        } else if (status === 404) {
          // That page is gone (deleted in another tab, or being deleted here):
          // stop saving IT, quietly — the rest of the board is unaffected.
          slot.stopped = true;
          slot.retry = null;
          slot.dirty = false;
        } else if (code === "workspace_changed" || status === 422 || status === 403) {
          failed = true;
          stopAll();
          deps.onRefused(code ?? String(status));
        } else {
          // Offline, 429, 5xx: the same rev and scene again, later.
          slot.retry = payload;
          scheduleRetry();
        }
        report();
        settleIfIdle();
      });
  }

  function scheduleRetry(): void {
    if (backoffTimer || ended) return;
    backoffTimer = setTimeout(() => {
      backoffTimer = null;
      for (const page of pages.keys()) send(page);
    }, backoff);
    backoff = Math.min(backoff * 2, MAX_BACKOFF_MS);
  }

  return {
    /** A page as the server sent it. */
    track(page: string, version: number, hash: number | (() => number)): void {
      pages.set(page, {
        version, hash: typeof hash === "number" ? hash : Number.NaN, lazyHash: typeof hash === "number" ? undefined : hash,
        build: null, dirty: false, sentHash: null, inFlight: false,
        retry: null, stopped: false, debounce: null, cancelIdle: null,
      });
    },

    /** A page restored from a draft starts dirty, edited from `version`. */
    restored(page: string, hash: number, build: () => string): void {
      const slot = pages.get(page);
      if (!slot) return;
      slot.hash = hash;
      slot.build = once(build);
      slot.dirty = true;
      send(page);
      report();
    },

    /** Excalidraw's onChange for a page; nothing happens unless its elements changed. */
    change(page: string, hash: number, build: () => string): void {
      const slot = pages.get(page);
      if (slot?.lazyHash) {
        slot.hash = slot.lazyHash();
        slot.lazyHash = undefined;
      }
      if (!slot || ended || !holding || slot.stopped || slot.hash === hash) return;
      slot.hash = hash;
      slot.build = once(build);
      slot.dirty = true;

      slot.cancelIdle ??= deps.idle(() => {
        slot.cancelIdle = null;
        void writeDraft(page, slot);
      });

      if (slot.debounce) clearTimeout(slot.debounce);
      slot.debounce = setTimeout(() => {
        slot.debounce = null;
        send(page);
      }, SERVER_DEBOUNCE_MS);
      report();
    },

    setHolding(value: boolean): void {
      holding = value;
      if (value) for (const page of pages.keys()) send(page);
    },

    /** The network came back: try the waiting saves now, not at the next backoff. */
    resume(): void {
      if (backoffTimer) clearTimeout(backoffTimer);
      backoffTimer = null;
      backoff = 1000;
      for (const page of pages.keys()) send(page);
    },

    /** Save every waiting page now — the tab is being hidden (best effort). */
    saveNow(): void {
      for (const [page, slot] of pages) {
        if (slot.debounce) clearTimeout(slot.debounce);
        slot.debounce = null;
        send(page);
      }
    },

    /** The page was deleted: its timers and its draft go with it. */
    remove(page: string): void {
      const slot = pages.get(page);
      if (slot?.debounce) clearTimeout(slot.debounce);
      slot?.cancelIdle?.();
      pages.delete(page);
      void deps.drafts.remove(key(page));
      report();
    },

    /** After the teacher chose in the conflict dialog: saving resumes from `version`. */
    resolved(page: string, version: number, hash: number, build: (() => string) | null): void {
      const slot = pages.get(page);
      if (!slot) return;
      slot.stopped = false;
      slot.version = version;
      slot.hash = hash;
      slot.build = build && once(build);
      slot.dirty = build !== null;
      if (!slot.dirty) void deps.drafts.remove(key(page));
      send(page);
      report();
    },

    /** Resolves once no PUT is in flight — the handover waits on it before releasing. */
    drain(): Promise<void> {
      return new Promise<void>((resolve) => {
        idleWaiters.push(resolve);
        settleIfIdle();
      });
    },

    /**
     * The tab is closing. Sends what it can with `keepalive` — never beside a PUT
     * already in flight, never over the 64 KB cap — and answers whether anything
     * is still unsaved, so the caller does NOT release the lock under a save that
     * is still travelling (the release would land first and the save die
     * `lock_lost`). The device draft covers whatever this misses.
     */
    flush(): { unsaved: boolean } {
      let unsaved = false;
      for (const [page, slot] of pages) {
        if (slot.inFlight) {
          unsaved = true;
          continue;
        }
        if (!holding || slot.stopped || (!slot.dirty && !slot.retry) || !slot.build) continue;
        unsaved = true;
        const payload = slot.retry ?? { rev: ++rev, scene: slot.build(), hash: slot.hash };
        // If the page comes back from the back/forward cache, `resume()` resends this
        // same rev: answered «already applied» if the keepalive landed, saved if not.
        slot.retry = payload;
        // The cap is on the whole body; the envelope (tab, version, rev, JSON escaping of
        // quotes) is well under a kilobyte.
        if (new TextEncoder().encode(payload.scene).length > KEEPALIVE_LIMIT - 1024) continue;
        deps.putKeepalive(page, { tab: deps.tab, version: slot.version, client_rev: payload.rev, scene: payload.scene });
      }
      return { unsaved };
    },

    stop: stopAll,
  };
}

export type Autosave = ReturnType<typeof createAutosave>;
