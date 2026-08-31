import { api } from "./api";

/**
 * Subscribing this browser to push, and unsubscribing it (spec 012 · US2 · T075).
 *
 * ⚠️ EVERY FUNCTION HERE ANSWERS RATHER THAN THROWS ON «NOT AVAILABLE». Push is
 * absent for reasons that are nobody's fault and nothing to act on — an insecure
 * origin, an iOS home screen the person has not installed to yet, a browser with
 * no support. FR-035: refusing this, or being unable to have it, disables nothing
 * else in the product.
 */

/** The VAPID public key, which is public by protocol — it is the JWT's `iss`. */
const VAPID_PUBLIC_KEY = process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY ?? "";

/**
 * The applicationServerKey has to be raw bytes, and the key travels as
 * Base64URL text.
 *
 * ⚠️ `atob` DOES NOT UNDERSTAND BASE64URL. `-` and `_` replace `+` and `/`, and
 * the padding is dropped — feed it the key unchanged and it throws
 * `InvalidCharacterError` on some keys and silently decodes the wrong bytes on
 * others, after which every subscription is refused by the push service with an
 * error that names nothing.
 */
function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = "=".repeat((4 - (base64.length % 4)) % 4);
  const normalised = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(normalised);
  const bytes = new Uint8Array(raw.length);

  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);

  return bytes;
}

/**
 * Whether this DEPLOYMENT has push at all.
 *
 * ⚠️ SEPARATE FROM `pushSupported()`, AND MERGING THE TWO PUTS A FALSE SENTENCE
 * ON THE SCREEN. The settings column appears whenever the channel class is
 * TAGGED — `ChannelRegistry::implemented()` does not ask `isEnabled()` — so a
 * server with no VAPID keys still shows the card; folded into one predicate, a
 * perfectly capable Chrome reads «this browser does not support push», which is
 * the reader hunting a setting in the wrong application entirely.
 */
export function pushConfigured(): boolean {
  return VAPID_PUBLIC_KEY !== "";
}

/** Whether this BROWSER can be subscribed, right now. */
export function pushSupported(): boolean {
  return (
    typeof window !== "undefined" &&
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    "Notification" in window &&
    pushConfigured()
  );
}

/**
 * The worker, or null.
 *
 * ⚠️ `navigator.serviceWorker.ready` NEVER SETTLES WHEN REGISTRATION FAILED —
 * blocked site data, a private window, an insecure origin. Awaited, the card that
 * calls this hangs on «loading» for the life of the tab and renders nothing:
 * invisible, and indistinguishable from a bug. `getRegistration()` resolves with
 * `undefined` instead of waiting for something that is never coming.
 */
async function workerRegistration(): Promise<ServiceWorkerRegistration | null> {
  if (!pushSupported()) return null;

  return (await navigator.serviceWorker.getRegistration()) ?? null;
}

/**
 * ⚠️ SAFARI GIVES A WEB PAGE NO PUSH AT ALL — only an app added to the home
 * screen gets it. So on iPhone the honest answer is not «not supported» but «add
 * it to your home screen first», and `pushSupported()` cannot tell the two apart:
 * before installation the APIs are simply missing.
 */
export function isIosSafari(): boolean {
  if (typeof navigator === "undefined") return false;

  const ua = navigator.userAgent;

  // iPadOS reports itself as a Mac, which the touch points give away.
  const iOS = /iPad|iPhone|iPod/.test(ua) || (ua.includes("Macintosh") && navigator.maxTouchPoints > 1);

  return iOS && !/CriOS|FxiOS|EdgiOS/.test(ua);
}

/** Whether the app is running from the home screen rather than in a tab. */
export function isInstalled(): boolean {
  if (typeof window === "undefined") return false;

  return (
    window.matchMedia?.("(display-mode: standalone)").matches === true ||
    // Safari's own, which predates the standard and is still what iOS answers.
    (navigator as unknown as { standalone?: boolean }).standalone === true
  );
}

/** The subscription this browser already holds, if any. */
export async function currentSubscription(): Promise<PushSubscription | null> {
  const registration = await workerRegistration();

  if (!registration) return null;

  return registration.pushManager.getSubscription();
}

/**
 * Ask for permission, subscribe, and register the device with the server.
 *
 * Returns `false` when the person declines or the browser cannot — never throws
 * for either, because neither is an error the reader should be shown.
 */
export async function subscribeToPush(): Promise<boolean> {
  if (!pushSupported()) return false;

  const permission = await Notification.requestPermission();

  if (permission !== "granted") return false;

  const registration = await workerRegistration();

  if (!registration) return false;

  /*
    ⚠️ `userVisibleOnly: true` IS NOT OPTIONAL — Chrome refuses any other value.
    It is a promise that every push shows a notification, which is why the worker
    displays one even for a body it could not parse.
  */
  const subscription =
    (await registration.pushManager.getSubscription()) ??
    (await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY) as BufferSource,
    }));

  /*
    `toJSON()` rather than reading `subscription.endpoint` and the keys by hand:
    the keys live in an ArrayBuffer that only this serialisation exposes as the
    Base64URL text the server stores.
  */
  const raw = subscription.toJSON() as {
    endpoint?: string;
    keys?: { p256dh?: string; auth?: string };
  };

  if (!raw.endpoint || !raw.keys?.p256dh || !raw.keys.auth) return false;

  await api.post("/notifications/push-subscriptions", {
    endpoint: raw.endpoint,
    keys: { p256dh: raw.keys.p256dh, auth: raw.keys.auth },
    user_agent: navigator.userAgent.slice(0, 255),
  });

  return true;
}

/**
 * Unsubscribe this browser and forget the row.
 *
 * ⚠️ THE SERVER IS TOLD BEFORE THE BROWSER UNSUBSCRIBES. The other way round, the
 * endpoint is gone from `subscription` by the time we need to name it, and the row
 * stays behind for ever — pushed to on every notification until the service
 * answers `410`, which for a subscription cancelled cleanly it may never do.
 */
export async function unsubscribeFromPush(): Promise<void> {
  const subscription = await currentSubscription();

  if (!subscription) return;

  await api.delete("/notifications/push-subscriptions", { endpoint: subscription.endpoint });

  await subscription.unsubscribe();
}
