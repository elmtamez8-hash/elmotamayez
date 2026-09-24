"use client";

import { useEffect } from "react";

import { isBuildAssetLoadFailure, reloadOnce, reloadOnceForStaleChunk } from "@/lib/stale-chunk";

/**
 * The NAVIGATION-time half of stale-chunk recovery; `app/error.tsx` is the
 * render-time half. See `lib/stale-chunk.ts` for the measured failure and why
 * the reload is guarded.
 *
 * ⚠️ IN THE ROOT LAYOUT, NOT THE PANEL SHELL, because a tab left open on the
 * marketplace breaks after a deploy exactly as one in the panel does.
 *
 * Three doors, because a missing chunk surfaces three ways:
 * - `unhandledrejection` — a dynamic `import()` or a prefetch nobody awaited.
 * - `error` on window — a thrown `ChunkLoadError` outside React.
 * - `error` in the CAPTURE phase on a `<script>`/`<link>` from `/_next/static/`
 *   — a resource load error does not bubble, so only capture sees it.
 *
 * Renders nothing. Registered once per document; the guard in `reloadOnce`
 * makes the three doors together cost at most one reload.
 */
export function StaleChunkRecovery() {
  useEffect(() => {
    const onError = (event: Event) => {
      if (isBuildAssetLoadFailure(event.target)) {
        reloadOnce();

        return;
      }

      if (event instanceof ErrorEvent) {
        reloadOnceForStaleChunk(event.error ?? event.message);
      }
    };

    const onRejection = (event: PromiseRejectionEvent) => {
      reloadOnceForStaleChunk(event.reason);
    };

    window.addEventListener("error", onError, true);
    window.addEventListener("unhandledrejection", onRejection);

    return () => {
      window.removeEventListener("error", onError, true);
      window.removeEventListener("unhandledrejection", onRejection);
    };
  }, []);

  return null;
}
