/**
 * The service worker (spec 012 · US2 · FR-011 · FR-012).
 *
 * ⚠️ IT IMPORTS `isCacheable`, AND THE IMPORT IS WHAT MAKES ITS TEST TRUE. Written
 * as a hand-copied `if` in a static `public/sw.js`, `sw-cache-policy.test.ts`
 * would be measuring a function nobody runs — green for ever while the worker
 * beside it cached a lesson video. One rule, one place, and the bundler is what
 * carries it here.
 *
 * ⚠️ WHICH IS ALSO WHY THIS FILE IS NOT IN `public/`. Next compiles it and serves
 * it from `/_next/static/`, so registering it at scope `/` needs the
 * `Service-Worker-Allowed` header — see `next.config.ts`. A narrow scope looks
 * entirely healthy from the outside: the registration succeeds, no error appears,
 * and nothing is ever cached and no push ever arrives.
 *
 * `lib: ["dom", ...]` in tsconfig gives us `Window`, not `ServiceWorkerGlobalScope`,
 * and adding `"webworker"` to that array makes the two sets of globals collide
 * across the whole project. The narrow local types below are the cost of not doing
 * that — deliberately minimal, naming only what this file touches.
 */
import { isCacheable } from "./sw-cache-policy";

type PushPayload = {
  title?: string;
  url?: string;
  uuid?: string;
};

interface ServiceWorkerScope {
  location: { origin: string };
  registration: {
    showNotification(title: string, options: Record<string, unknown>): Promise<void>;
  };
  clients: {
    matchAll(options: { type: string; includeUncontrolled: boolean }): Promise<
      Array<{ url: string; focus(): Promise<unknown>; navigate?(url: string): Promise<unknown> }>
    >;
    openWindow(url: string): Promise<unknown>;
    claim(): Promise<void>;
  };
  skipWaiting(): Promise<void>;
  addEventListener(type: string, listener: (event: never) => void): void;
}

const sw = self as unknown as ServiceWorkerScope;

/*
  ⚠️ THE VERSION IS PART OF THE CACHE NAME, AND OLD ONES ARE DELETED ON ACTIVATE.
  Without that, a policy change that stops allowing a prefix leaves everything it
  already cached served for ever from an entry nothing will ever evict — the rule
  tightened and the device kept the old answer.
*/
const CACHE = "mteatch-v1";

/**
 * ⚠️ PRECACHED, BECAUSE A FALLBACK FETCHED ON DEMAND IS NOT A FALLBACK. `/offline`
 * is only ever shown when the network is gone, which is exactly the moment it
 * cannot be downloaded — cached lazily it would be a page that appears for the
 * second outage and never the first.
 */
const OFFLINE_PAGE = "/offline";

sw.addEventListener("install", ((event: {
  waitUntil(promise: Promise<unknown>): void;
}) => {
  /*
    FR-012 — a new build takes over at once rather than waiting for every tab to
    close. A worker that waits is how a fixed bug keeps being reported: the code
    is deployed and the device is still running last week's.
  */
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.addAll([OFFLINE_PAGE, "/brand/icon-192.png"]))
      // An install that fails takes the whole worker with it, so a missing asset
      // would cost caching AND push. The fallback page is worth having; it is not
      // worth that.
      .catch(() => undefined)
      .then(() => sw.skipWaiting()),
  );
}) as (event: never) => void);

sw.addEventListener("activate", ((event: {
  waitUntil(promise: Promise<unknown>): void;
}) => {
  event.waitUntil(
    caches
      .keys()
      .then((names) => Promise.all(names.filter((name) => name !== CACHE).map((name) => caches.delete(name))))
      .then(() => sw.clients.claim()),
  );
}) as (event: never) => void);

sw.addEventListener("fetch", ((event: {
  request: Request;
  respondWith(response: Promise<Response>): void;
}) => {
  const request = event.request;

  /*
    ⚠️ ONLY A `GET` IS EVER CONSIDERED. A `POST` is an instruction — a booking, an
    answer, a payment — and replaying one from a cache is performing it twice.
  */
  if (request.method !== "GET") return;

  /*
    ⚠️ THE ONE ROUTE THAT REACHES `/offline`. Written without this the page is a
    screen nothing links to — built, tested, and never once shown — because
    `isCacheable` refuses to cache pages, so a failed navigation would fall
    through to the browser's own «you are not connected» error and the file would
    sit in the repository as decoration.

    Network-FIRST, never cache-first: this must not intercept a navigation that
    would have worked. It answers only what the network refuses to.
  */
  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request).catch(() =>
        caches
          .match(OFFLINE_PAGE)
          .then((hit) => hit ?? Response.error()),
      ),
    );

    return;
  }

  if (!isCacheable(request.url, sw.location.origin)) return;

  /*
    Cache-first, which is safe for exactly this allowlist and nothing else:
    everything on it is content-hashed or static. A route whose CONTENT changes
    would be stale here for ever, which is why the policy refuses pages.
  */
  event.respondWith(
    caches.match(request).then((hit) => {
      if (hit) return hit;

      return fetch(request).then((response) => {
        // A partial or errored response cached is a broken asset pinned to the
        // device until the cache name changes.
        if (response.ok && response.status === 200) {
          const copy = response.clone();
          void caches.open(CACHE).then((cache) => cache.put(request, copy));
        }

        return response;
      });
    }),
  );
}) as (event: never) => void);

sw.addEventListener("push", ((event: {
  data: { json(): PushPayload } | null;
  waitUntil(promise: Promise<unknown>): void;
}) => {
  let payload: PushPayload = {};

  try {
    payload = event.data?.json() ?? {};
  } catch {
    // A body we cannot read is still a message worth showing: the browser demands
    // a visible notification for every push (`userVisibleOnly`), and showing
    // nothing is how a browser revokes the permission entirely.
    payload = {};
  }

  /*
    ⚠️ A TITLE AND A LINK, BECAUSE THAT IS ALL THE SERVER SENDS. The message body
    deliberately stays behind a sign-in: the protocol has no revocation but `410`,
    and a phone is shared between a guardian and their child — an absence report
    on a lock screen is read by whoever is standing beside it.
  */
  event.waitUntil(
    sw.registration.showNotification(payload.title ?? "إشعار جديد", {
      body: "افتح التطبيق لقراءة التفاصيل.",
      icon: "/brand/icon-192.png",
      badge: "/brand/icon-192.png",
      dir: "rtl",
      lang: "ar",
      // Same tag ⇒ the second alert replaces the first rather than stacking, so a
      // burst does not bury a lock screen.
      tag: payload.uuid ?? "mteatch",
      data: { url: payload.url ?? "/notifications" },
    }),
  );
}) as (event: never) => void);

sw.addEventListener("notificationclick", ((event: {
  notification: { close(): void; data?: { url?: string } };
  waitUntil(promise: Promise<unknown>): void;
}) => {
  event.notification.close();

  const target = event.notification.data?.url ?? "/notifications";

  /*
    Focus a tab that is already open rather than adding a fourth copy of the app to
    a phone's task switcher — and then NAVIGATE it.

    ⚠️ FOCUS ALONE IS THE BUG THAT LOOKS LIKE IT WORKS. The app comes to the front
    showing whatever the person was last reading, so tapping an alert about a
    lesson lands them on the billing screen and the alert appears to do nothing.
    `navigate` is optional on older implementations, hence the fallback.
  */
  event.waitUntil(
    sw.clients
      .matchAll({ type: "window", includeUncontrolled: true })
      .then((clients) => {
        const open = clients[0];

        if (!open) return sw.clients.openWindow(target);

        return Promise.resolve(open.navigate?.(target)).then(() => open.focus());
      }),
  );
}) as (event: never) => void);
