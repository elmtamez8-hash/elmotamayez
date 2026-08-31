"use client";

import { useEffect } from "react";

/**
 * Registers the service worker, once, for the whole signed-in shell
 * (spec 012 · US2 · T062).
 *
 * Renders nothing. It is a component rather than a call inside the layout so the
 * effect has a single owner — mounted twice, `register()` is idempotent, but the
 * update handler below is not.
 *
 * ⚠️ `new URL("...", import.meta.url)` IS THE WHOLE REASON THE WORKER CAN IMPORT
 * ANYTHING. It is what makes the bundler compile `service-worker.ts` — and with
 * it `sw-cache-policy.ts`, the module `sw-cache-policy.test.ts` actually measures.
 * A string path to a static `public/sw.js` would leave that test guarding a
 * function no worker runs.
 *
 * ⚠️ AND THE SCOPE MUST BE `/`, WHICH IS NOT FREE. The compiled worker is served
 * from `/_next/static/`, and a worker may by default only control paths beneath
 * its own — so without the `Service-Worker-Allowed` header in `next.config.ts`
 * this registration SUCCEEDS, reports no error, and controls nothing: no page is
 * cached and no push is ever delivered. Read the scope in
 * DevTools › Application › Service Workers rather than trusting that it resolved.
 */
export function ServiceWorkerRegistrar() {
  useEffect(() => {
    if (typeof navigator === "undefined" || !("serviceWorker" in navigator)) return;

    /*
      A service worker needs a secure context, and `localhost` is one while a LAN
      address is not — so this is silently unavailable on the phone somebody tests
      with and perfectly fine on the laptop serving the page. Not an error worth
      showing: nothing the person can do about it, and everything else works.
    */
    navigator.serviceWorker
      .register(new URL("../../lib/service-worker.ts", import.meta.url), {
        scope: "/",
        /*
          FR-012 — `"none"` stops the BROWSER serving a cached copy of the worker
          script itself when checking for updates. Left at the default, a new build
          can sit undiscovered behind an HTTP cache entry for up to 24 hours, and
          the fix everybody deployed is one the device never sees.
        */
        updateViaCache: "none",
      })
      .then((registration) => {
        // Ask immediately as well: a long-lived installed app may not navigate for
        // days, and `register()` alone only checks on navigation.
        void registration.update();
      })
      .catch(() => {
        /*
          Swallowed deliberately, and it is the one place in this product where
          that is right. Every failure here — an insecure origin, a browser with no
          support, a user who blocked storage — leaves the application working
          exactly as it did before offline support existed. There is nothing to
          tell the reader and nothing they could act on.
        */
      });
  }, []);

  return null;
}
