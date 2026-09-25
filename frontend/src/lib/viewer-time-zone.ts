"use client";

import { useSyncExternalStore } from "react";

import { browserTimeZone, resolveViewerTimeZone } from "@/lib/timezone";

/**
 * `useViewerTimeZone()` — THE one way a screen learns which clock to draw on.
 *
 * ⚠️ A STORE, NOT A READ OF `useAuth()`. Session times are drawn by leaf
 * components (`SessionCard`, `CancelBookingButton`, `NextSessionCountdown`, …)
 * that are rendered on public pages and in unit tests with no `AuthProvider`
 * above them, where `useAuth()` throws. `AuthProvider` pushes the account's
 * stored zone in here when it loads the user, and every reader subscribes.
 *
 * ⚠️ AND `useSyncExternalStore` FOR THE BROWSER'S ZONE, NOT `useState(Intl…)`.
 * The server render has no browser zone (it would print the server's, UTC), so
 * the server snapshot is `null` and the first client paint agrees with it; the
 * real zone arrives on the next render. A `useState` initialiser would read the
 * browser on the client and the server's zone on the server — a hydration
 * mismatch on every page with a time on it.
 */

let storedZone: string | null = null;
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  return () => listeners.delete(listener);
}

function noopSubscribe(): () => void {
  return () => {};
}

/** Called by `AuthProvider` whenever the signed-in account (and so its zone) changes. */
export function setStoredViewerTimeZone(zone: string | null | undefined): void {
  const next = zone ?? null;

  if (next === storedZone) return;

  storedZone = next;
  listeners.forEach((listener) => listener());
}

export function useViewerTimeZone(): string {
  const stored = useSyncExternalStore(subscribe, () => storedZone, () => null);
  const browser = useSyncExternalStore(noopSubscribe, browserTimeZone, () => null);

  return resolveViewerTimeZone(stored, browser);
}
