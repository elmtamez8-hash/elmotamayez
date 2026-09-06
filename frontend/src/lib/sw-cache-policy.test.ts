import { describe, expect, it } from "vitest";

import { isCacheable } from "./sw-cache-policy";

/*
  Spec 012 · US2 · SC-005 — zero protected asset is ever kept on the device.

  ⚠️ THE POSITIVE CONTROL IS THE HALF THAT MAKES THE REST MEAN ANYTHING. This is
  an allowlist, so a build that returned `false` for everything would satisfy
  every refusal below perfectly — and cache nothing at all, which is the feature
  not existing. Each block asserts what must be refused AND what must be kept.
*/

const ORIGIN = "https://mteatch.test";

describe("isCacheable", () => {
  it("keeps the build's own immutable assets", () => {
    // Content-hashed, so a stale entry is impossible by construction.
    expect(isCacheable("/_next/static/chunks/main-9f2c.js", ORIGIN)).toBe(true);
    expect(isCacheable("/_next/static/css/app.css", ORIGIN)).toBe(true);
  });

  it("refuses the build's assets when their names are not hashed", () => {
    /*
      ⚠️ THE HASHING IS THE WHOLE JUSTIFICATION FOR CACHING THIS PREFIX FIRST, and
      under `next dev` it does not hold: the chunk is served as
      `chunks/app/(app)/certificates/verify/[code]/page.js`, a STABLE name
      (measured 2026-09-06). Cache-first then pins an edited page on the device for
      ever — a hard reload, a cache-busting query, a dev-server restart, deleting
      `.next` and clearing the browser cache all fail to shift it, and only
      unregistering the worker does. Three changes in one session were diagnosed as
      «the server is wrong» because of it.
    */
    expect(isCacheable("/_next/static/chunks/app/page.js", ORIGIN, false)).toBe(false);
    expect(isCacheable("/_next/static/css/app.css", ORIGIN, false)).toBe(false);

    // ⚠️ And the positive control for the SAME argument: everything else on the
    // allowlist still works, so push and the offline page stay testable in
    // development instead of the fix quietly disabling half the feature.
    expect(isCacheable("/brand/icon-192.png", ORIGIN, false)).toBe(true);
    expect(isCacheable("/offline", ORIGIN, false)).toBe(true);
  });

  it("keeps the brand assets, the manifest and the offline page", () => {
    expect(isCacheable("/brand/icon-192.png", ORIGIN)).toBe(true);
    expect(isCacheable("/manifest.webmanifest", ORIGIN)).toBe(true);
    expect(isCacheable("/offline", ORIGIN)).toBe(true);
  });

  it("refuses every playback route", () => {
    /*
      A grant is short-lived and the watermark renews it; cached bytes outlive
      both, and the expiry decision that lives on the server stops being able to
      cut a video already on disk.
    */
    expect(isCacheable("/playback/abc-123/manifest.m3u8", ORIGIN)).toBe(false);
    expect(isCacheable("/playback/abc-123/segment-0.ts", ORIGIN)).toBe(false);
    expect(isCacheable("/playback/abc-123/captions/xyz", ORIGIN)).toBe(false);
  });

  it("refuses the video host outright, whatever the path", () => {
    /*
      ⚠️ CROSS-ORIGIN IS REFUSED BEFORE ANY PATH IS READ, so the CDN is out
      without this file naming a vendor — which is what stops the next provider
      walking straight in. The token in the URL expires; a cached response does
      not.
    */
    expect(isCacheable("https://vz-abc.b-cdn.net/xyz/playlist.m3u8", ORIGIN)).toBe(false);
    expect(isCacheable("https://vz-abc.b-cdn.net/xyz/720p/video0.ts", ORIGIN)).toBe(false);
    expect(isCacheable("https://evil.example.com/_next/static/x.js", ORIGIN)).toBe(false);
  });

  it("refuses the whole API", () => {
    // A balance, a timetable and an attendance row are answers about right now.
    expect(isCacheable("/api/v1/notifications", ORIGIN)).toBe(false);
    expect(isCacheable("/api/v1/billing/balances", ORIGIN)).toBe(false);
  });

  it("refuses a page it was never told about", () => {
    /*
      ⚠️ THE DEFAULT ANSWER IS «NO», WHICH IS THE WHOLE DIFFERENCE BETWEEN AN
      ALLOWLIST AND A DENYLIST. A route added next month is uncached until
      somebody writes it down — rather than cached until somebody remembers to
      forbid it.
    */
    expect(isCacheable("/dashboard", ORIGIN)).toBe(false);
    expect(isCacheable("/learn/lessons/abc", ORIGIN)).toBe(false);
    expect(isCacheable("/manage/billing/students", ORIGIN)).toBe(false);
  });

  it("refuses the image optimiser, which takes its target as a parameter", () => {
    // An allowlist entry for `/_next/image` is an allowlist entry for whatever
    // `?url=` names — including a signed playback address.
    expect(isCacheable("/_next/image?url=%2Fplayback%2Fabc%2Ffile&w=640", ORIGIN)).toBe(false);
  });

  it("refuses an address it cannot parse", () => {
    expect(isCacheable("::::not a url", "also not an origin")).toBe(false);
  });
});
