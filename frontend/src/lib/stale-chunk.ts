/**
 * Recovering a tab that outlived a deploy — ONE reload, never a loop.
 *
 * ⛔ MEASURED ON PRODUCTION 2026-09-24: a tab opened before a deploy navigates
 * and asks for `/_next/static/chunks/5613-0374f08bd3eff5a8.js`, which the new
 * build deleted (404) — the fresh HTML names `5613-836749482bb11ad1.js`. Every
 * page then showed «تعذّر عرض هذه الصفحة» until a hard reload, and we deploy
 * several times a day, so every open tab broke after every release.
 *
 * A full document load is the only thing that fixes it: `reset()` re-renders
 * with the client the tab already holds, and that client's chunk map names
 * files that no longer exist. So a chunk failure reloads the current URL once.
 *
 * ⚠️ THE GUARD IS THE LOAD-BEARING HALF. If the fresh document ALSO fails to
 * load a chunk (a deploy still half-rolled out, the frontend container down, a
 * browser serving stale HTML from its own cache), an unguarded reload is an
 * infinite reload loop on the user's device — worse than the error screen it
 * replaced, because the error screen at least has buttons. So a reload is
 * stamped in `sessionStorage` (per tab, survives the reload, dies with the tab)
 * and a second chunk failure inside the window shows the error UI instead.
 * If storage is unavailable (private mode, blocked site data) there is no way
 * to prove we have not just reloaded, so we DO NOT reload: the error screen,
 * whose second button is a reload, is the safe answer.
 *
 * ⚠️ The server half lives in `docker/nginx.prod.conf`: documents and RSC
 * payloads are sent `no-cache` there, because Next's own
 * `stale-while-revalidate` let the browser show OLD HTML from its cache — and
 * when the missing chunk is the root layout's, no boundary of ours ever runs.
 * This file covers what that cannot: a tab whose JavaScript is already old.
 */

export const STALE_CHUNK_RELOAD_KEY = "stale-chunk-reload-at";

/** A reload inside this window is assumed to be ours, so no second one. */
export const STALE_CHUNK_GUARD_MS = 60_000;

/*
  Every spelling a failed chunk takes, by bundler and browser:
  - webpack: `ChunkLoadError`, «Loading chunk 5613 failed.» / «Loading CSS chunk … failed»
  - Next / Turbopack: «Failed to load chunk …»
  - native dynamic import: Chrome «Failed to fetch dynamically imported module»,
    Firefox «error loading dynamically imported module», Safari «Importing a
    module script failed».
*/
const CHUNK_MESSAGE =
  /Loading (CSS )?chunk [\w-]+ failed|Failed to load chunk|Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed/i;

export function isChunkLoadError(error: unknown): boolean {
  if (!error || typeof error !== "object") {
    return typeof error === "string" && CHUNK_MESSAGE.test(error);
  }

  const { name, message } = error as { name?: unknown; message?: unknown };

  if (name === "ChunkLoadError") return true;

  return typeof message === "string" && CHUNK_MESSAGE.test(message);
}

/**
 * Reloads the current URL if `error` is a failed chunk and no reload of ours
 * happened inside the guard window. Returns whether it reloaded.
 */
export function reloadOnceForStaleChunk(error: unknown, now: number = Date.now()): boolean {
  if (!isChunkLoadError(error)) return false;

  return reloadOnce(now);
}

/** The guarded reload itself — shared with the `<script>` load-error path. */
export function reloadOnce(now: number = Date.now()): boolean {
  if (typeof window === "undefined") return false;

  try {
    const last = Number(window.sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY));

    if (Number.isFinite(last) && last > 0 && now - last < STALE_CHUNK_GUARD_MS) {
      return false;
    }

    window.sessionStorage.setItem(STALE_CHUNK_RELOAD_KEY, String(now));
  } catch {
    // No storage, no proof we have not just reloaded — see the docblock.
    return false;
  }

  window.location.reload();

  return true;
}

/** A `<script>`/`<link>` from the build output that failed to load. */
export function isBuildAssetLoadFailure(target: EventTarget | null): boolean {
  if (typeof HTMLScriptElement !== "undefined" && target instanceof HTMLScriptElement) {
    return target.src.includes("/_next/static/");
  }

  if (typeof HTMLLinkElement !== "undefined" && target instanceof HTMLLinkElement) {
    return target.rel === "stylesheet" && target.href.includes("/_next/static/");
  }

  return false;
}
