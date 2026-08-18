import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { PlaybackGrant } from "@/lib/media";

/*
| SC-012 · هـ-٥ — «FATAL» IS NOT «OVER», AND THE PLAYER USED TO TREAT IT AS OVER.
|
| hls.js raises a fatal NETWORK_ERROR for a segment that timed out or was refused —
| which is exactly what a signing key rotated mid-lesson produces — and a fatal
| MEDIA_ERROR for a decoder that stalled, common on Android Chrome. Both have a
| documented recovery, `startLoad()` and `recoverMediaError()`, and neither was
| called: the student lost the rest of the lesson to a banner over a video that was
| fine, until they thought to reload the page.
|
| ⚠️ THIS IS THE CRITERION THAT COULD NOT BE MEASURED BEFORE THIS FILE EXISTED. It
| is unreachable from the backend suite, and reproducing it in Playwright would mean
| rotating a real signing key against a real CDN mid-playback. With the library
| mocked it is three lines: hand the component a fake Hls, take the ERROR handler it
| registers, and call it.
|
| The library is mocked rather than stubbed at the network, because what is under
| test is OUR reaction to ITS verdict — `data.fatal` is hls.js's own judgement, and a
| test that tried to provoke it for real would be testing hls.js.
*/

const startLoad = vi.fn();
const recoverMediaError = vi.fn();
const destroy = vi.fn();

/** The handler VideoPlayer registers, captured so the test can be the library. */
let onError: ((event: string, data: { fatal: boolean; type: string }) => void) | null = null;

const ErrorTypes = { NETWORK_ERROR: "networkError", MEDIA_ERROR: "mediaError", OTHER_ERROR: "otherError" };

vi.mock("hls.js", () => {
  class FakeHls {
    static Events = { ERROR: "hlsError" };

    static ErrorTypes = ErrorTypes;

    static isSupported = () => true;

    on(event: string, handler: (e: string, d: { fatal: boolean; type: string }) => void) {
      if (event === FakeHls.Events.ERROR) onError = handler;
    }

    loadSource() {}

    attachMedia() {}

    startLoad = startLoad;

    recoverMediaError = recoverMediaError;

    destroy = destroy;
  }

  return { default: FakeHls };
});

const grant: PlaybackGrant = {
  grant: "grant-1",
  expires_at: new Date("2026-08-19T12:00:00Z").toISOString(),
  manifest_url: "/api/v1/playback/grant-1/stream",
  format: "hls",
  watermark: { name: "طالب", phone_masked: null },
  renew_after_seconds: 60,
  reload_after_seconds: null,
  duration_seconds: 120,
  resume_at_seconds: 0,
  renditions: [],
  captions: [],
};

const BANNER = "تعذّر تشغيل الفيديو. حدّث الصفحة وحاول مجدداً.";

beforeEach(() => {
  onError = null;
  startLoad.mockClear();
  recoverMediaError.mockClear();

  // What makes the component take the library path at all: jsdom plays no HLS
  // natively, and `isMseSupported()` asks for this exact global.
  (globalThis as { MediaSource?: unknown }).MediaSource = class {};
});

afterEach(() => {
  delete (globalThis as { MediaSource?: unknown }).MediaSource;
});

/** Render, then wait for the dynamic `import("hls.js")` to have registered its handler. */
async function mountPlayer(): Promise<void> {
  const { VideoPlayer } = await import("./VideoPlayer");

  render(<VideoPlayer grant={grant} />);

  await waitFor(() => expect(onError).not.toBeNull());
}

describe("VideoPlayer", () => {
  it("recovers from a fatal network error rather than ending the lesson", async () => {
    await mountPlayer();

    onError?.("hlsError", { fatal: true, type: ErrorTypes.NETWORK_ERROR });

    expect(startLoad).toHaveBeenCalledTimes(1);
    expect(screen.queryByText(BANNER)).toBeNull();
  });

  it("recovers from a fatal media error rather than ending the lesson", async () => {
    await mountPlayer();

    onError?.("hlsError", { fatal: true, type: ErrorTypes.MEDIA_ERROR });

    expect(recoverMediaError).toHaveBeenCalledTimes(1);
    expect(screen.queryByText(BANNER)).toBeNull();
  });

  /*
   | One attempt per kind, then the banner. A recovery loop that never gives up is a
   | page pinning the CPU on a video that is genuinely broken — so the second failure
   | of the same kind has to be allowed to end it.
   */
  it("gives up on the second failure of the same kind", async () => {
    await mountPlayer();

    onError?.("hlsError", { fatal: true, type: ErrorTypes.NETWORK_ERROR });
    onError?.("hlsError", { fatal: true, type: ErrorTypes.NETWORK_ERROR });

    expect(startLoad).toHaveBeenCalledTimes(1);
    expect(await screen.findByText(BANNER)).toBeTruthy();
  });

  /*
   | Everything hls.js does NOT call fatal it recovers from by itself — a banner over
   | those would sit on top of a lesson that is still playing.
   */
  it("says nothing about an error the library does not call fatal", async () => {
    await mountPlayer();

    onError?.("hlsError", { fatal: false, type: ErrorTypes.NETWORK_ERROR });

    expect(startLoad).not.toHaveBeenCalled();
    expect(screen.queryByText(BANNER)).toBeNull();
  });

  /*
   | And the branch that started 019: `format: "hls"` used to be refused in words on
   | every browser but Safari, so the day a provider emitted a manifest every student
   | not on Safari read «صيغة غير مدعومة» on a lesson that was perfectly fine. With
   | MSE present the library must be loaded instead — proven by the handler existing.
   */
  it("plays HLS on a browser with Media Source Extensions", async () => {
    await mountPlayer();

    expect(screen.queryByText("صيغة غير مدعومة")).toBeNull();
  });
});
