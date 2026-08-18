"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { TranscriptPanel } from "@/components/player/TranscriptPanel";
import { Watermark } from "@/components/player/Watermark";
import { Alert } from "@/components/ui/Alert";
import { type PlaybackGrant } from "@/lib/media";
import { Select } from "@/components/ui/Field";

/**
 * A native <video> element, and deliberately nothing more.
 *
 * The browser already gives us everything the requirements ask for: full
 * keyboard control, playback speed, captions via <track>, and no download button
 * via controlsList. Its built-in controls are also more accessible than any
 * custom set we would write to replace them, which is most of why the
 * accessibility criterion is reachable at all.
 *
 * What it does not give is HLS outside Safari, and spec 019 is when that stopped
 * being hypothetical: a commercial provider emits a segmented manifest, so
 * `format === "hls"` now arrives for real. Until then this component refused it in
 * words — correct at the time, and the moment a provider produced one it would
 * have meant every student not on Safari reading «صيغة غير مدعومة» on a lesson
 * that was perfectly fine.
 *
 * ⚠️ SO hls.js IS LOADED, AND ONLY WHERE IT IS NEEDED. Safari plays HLS natively
 * and gets no library; a browser with Media Source Extensions gets one, imported
 * dynamically so it is absent from the bundle of every progressive lesson. A
 * browser with neither still says so in words rather than showing a black
 * rectangle.
 *
 * What does NOT change is where the manifest comes from: the player asks our own
 * `/playback/{grant}/stream`.
 *
 * ⚠️ BUT FOR A REDIRECT PROVIDER THAT TRIP HAPPENS ONCE, NOT PER BYTE — WHICH IS WHY
 * `reload_after_seconds` EXISTS. A provider that serves the bytes itself is
 * re-authorised on every range request, so the grant lapsing cuts playback where it
 * stands. A provider that answers a 302 hands the browser a URL signed for the
 * grant's expiry as it stood at that moment, and everything after that is
 * browser↔CDN. Renewal extends the grant ROW and cannot reach that URL — so without
 * coming back through our route the lesson stops at the first token expiry and no
 * amount of renewing helps. The reload below is what keeps the server the decision
 * point; the cost is a re-buffer on each one, and the server sets the cadence.
 */
export function VideoPlayer({ grant }: { grant: PlaybackGrant }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [error, setError] = useState("");
  const [source, setSource] = useState(grant.manifest_url);
  const [speed, setSpeed] = useState(1);

  /*
    Where to land after a reload of the source, or null before the first one.

    State rather than a ref: the position is read at the moment the reload is
    decided, and reading a ref during render to compute a start position would be
    impure — a double render would have to agree with itself for the resume to be
    right. No counter beside it either; the busted `source` string is what re-runs
    the effects, so a second value would be one nothing reads.
  */
  const [restartAt, setRestartAt] = useState<number | null>(null);

  // Three ways a browser can play what we are about to hand it, and only the
  // third needs a library.
  const usesLibrary = grant.format === "hls" && !isNativeHlsSupported();
  const canPlay =
    grant.format === "progressive" || isNativeHlsSupported() || isMseSupported();

  // The default track is the one worth reading as prose; a translation of the
  // same lesson would repeat it.
  const transcript = grant.captions.find((caption) => caption.is_default) ?? grant.captions[0];

  /*
    Renewal belongs to the watermark, not here — see Watermark.tsx.

    ⚠️ AND IT NO LONGER REPORTS A URL. It used to hand back `manifest_url` and this
    component assigned it to the source; harmless while that string was constant, and
    actively wrong now — it would overwrite the reloaded source every renewal and
    restart playback from the top with no saved position. Renewal keeps the grant
    alive; the reload effect owns where the player reads from. Two writers to one
    source is how a renewal silently moves a viewer.
  */
  const onStopped = useCallback((message: string) => setError(message), []);

  // Where playback should land: what the server remembers on first load, and
  // wherever the viewer actually was on every reload after that.
  const startAt = restartAt ?? grant.resume_at_seconds;
  const startFrom = Number.isFinite(startAt) && startAt > 0 ? startAt : 0;

  /*
    Pick up where they left off (FR-036).

    ⚠️ THE NATIVE PATH ONLY, BECAUSE THIS ASSIGNMENT DOES NOTHING WHEN hls.js DRIVES
    THE ELEMENT. With no `src` yet, setting `currentTime` sets the element's DEFAULT
    playback start position — and `attachMedia` then points `src` at a MediaSource
    blob, which re-runs resource selection and resets it to zero. The library path
    passes the position to hls.js as `startPosition` instead, which is the only hook
    that survives that swap.
  */
  useEffect(() => {
    const video = videoRef.current;

    // Finite check, not just > 0: a missing field arrives as undefined, and
    // assigning that to currentTime throws — which took the whole page down with
    // a client-side exception rather than merely starting from zero.
    if (usesLibrary || video === null || startFrom === 0) return;

    // On a reload the element is fetching a new `src`, so the seek has to wait for
    // it to know how long the video is.
    if (video.readyState === 0) {
      const seek = () => {
        video.currentTime = startFrom;
      };

      video.addEventListener("loadedmetadata", seek, { once: true });

      return () => video.removeEventListener("loadedmetadata", seek);
    }

    video.currentTime = startFrom;
  }, [usesLibrary, startFrom, source]);

  /*
    Come back through our own route before the signed URL the player is holding
    lapses — see the note on the component.

    Null means the provider serves its own bytes through `stream()`, so every
    request is already re-authorised and a reload would be a re-buffer that buys
    nothing.
  */
  useEffect(() => {
    const cadence = grant.reload_after_seconds;

    if (cadence === null || cadence <= 0) return;

    const timer = window.setInterval(() => {
      const at = videoRef.current?.currentTime ?? 0;

      // A changed string is what makes the browser re-request on the native path;
      // the library path is rebuilt by the effect's dependency either way.
      setSource(`${grant.manifest_url}?r=${Date.now()}`);
      setRestartAt(at);
    }, cadence * 1000);

    return () => window.clearInterval(timer);
  }, [grant.reload_after_seconds, grant.manifest_url]);

  /*
    Attach hls.js, and only when this browser actually needs it.

    Imported dynamically so a progressive lesson never downloads a player library
    it has no use for. Destroyed on cleanup: without that, navigating between two
    recorded lessons leaves the previous instance fetching segments against a grant
    the viewer has left behind.
  */
  useEffect(() => {
    if (!usesLibrary) return;

    let cancelled = false;
    let instance: { destroy: () => void } | null = null;

    void (async () => {
      const { default: Hls } = await import("hls.js");
      const video = videoRef.current;

      if (cancelled || video === null) return;

      if (!Hls.isSupported()) {
        setError("متصفّحك لا يدعم تشغيل هذا الفيديو. جرّب متصفّحاً آخر أو حدّث متصفّحك.");
        return;
      }

      // startPosition, not a currentTime assignment: hls.js owns the element's
      // source, so this is the only place a resume survives attachMedia. -1 is the
      // library's own "from the beginning".
      const hls = new Hls({ startPosition: startFrom > 0 ? startFrom : -1 });
      instance = hls;

      /*
       * Only the errors the library itself calls fatal. It recovers from the rest
       * on its own, and surfacing those would put a banner over a lesson that is
       * still playing.
       *
       * ⚠️ AND «FATAL» IS NOT «OVER» — THIS USED TO END THE LESSON ON A HICCUP.
       *
       * hls.js raises a fatal network error for a segment that timed out and a
       * fatal media error for a decoder that stalled, and BOTH have a documented
       * recovery: `startLoad()` and `recoverMediaError()`. Neither was called. A
       * student on Android Chrome — where a stalled decode is common — lost the
       * rest of the lesson to a banner until they reloaded the page, over a
       * transient the library was built to absorb.
       *
       * One attempt per kind, then the banner: a recovery loop that never gives up
       * is a page that pins the CPU on a video that is genuinely broken. And the
       * error is CLEARED on the way in, so a banner cannot outlive the recovery
       * that fixed it.
       */
      let recoveredNetwork = false;
      let recoveredMedia = false;

      hls.on(Hls.Events.ERROR, (_event, data) => {
        if (!data.fatal) return;

        if (data.type === Hls.ErrorTypes.NETWORK_ERROR && !recoveredNetwork) {
          recoveredNetwork = true;
          setError("");
          hls.startLoad();
          return;
        }

        if (data.type === Hls.ErrorTypes.MEDIA_ERROR && !recoveredMedia) {
          recoveredMedia = true;
          setError("");
          hls.recoverMediaError();
          return;
        }

        setError("تعذّر تشغيل الفيديو. حدّث الصفحة وحاول مجدداً.");
      });

      hls.loadSource(source);
      hls.attachMedia(video);
    })();

    return () => {
      cancelled = true;
      instance?.destroy();
    };
  }, [usesLibrary, source, startFrom]);

  if (!canPlay) {
    return (
      <Alert tone="warning" title="صيغة غير مدعومة">
        متصفّحك لا يدعم تشغيل هذا الفيديو. جرّب متصفّحاً آخر أو حدّث متصفّحك.
      </Alert>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      {error !== "" && <Alert tone="danger" title="توقّف التشغيل">{error}</Alert>}

      <div className="relative">
        <video
          ref={videoRef}
          // Left unset when the library drives it: hls.js owns the element's
          // source, and setting both makes the browser fetch a manifest it cannot
          // parse and report an error over a video that plays.
          src={usesLibrary ? undefined : source}
          controls
          // Removes the download item from the browser's own menu. Not a
          // protection on its own — the grant expiring is — but there is no
          // reason to offer the button.
          controlsList="nodownload"
          preload="metadata"
          playsInline
          onError={() => setError("تعذّر تشغيل الفيديو. حدّث الصفحة وحاول مجدداً.")}
          className="w-full rounded-xl bg-surface"
        >
          {grant.captions.map((caption) => (
            <track
              key={caption.uuid}
              kind="captions"
              src={caption.url}
              srcLang={caption.language}
              label={caption.language === "ar" ? "العربية" : caption.language}
              default={caption.is_default}
            />
          ))}
        </video>

        <Watermark
          grant={grant}
          videoRef={videoRef}
          onStopped={onStopped}
        />
      </div>

      {/*
        Speed is in the browser's own overflow menu, but buried differently in
        each one. A student re-watching an explanation they did not follow should
        not have to find it — FR-033 is about the capability being reachable.
      */}
      <div className="flex items-center gap-2">
        <label htmlFor="playback-speed" className="text-sm text-ink-muted">
          سرعة العرض
        </label>
        <Select
          id="playback-speed"
          chevron="sm"
          value={speed}
          onChange={(e) => {
            const rate = Number(e.target.value);
            setSpeed(rate);
            if (videoRef.current !== null) videoRef.current.playbackRate = rate;
          }}
          className="rounded-lg border border-line bg-surface-raised px-2 py-1 text-sm text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          {SPEEDS.map((rate) => (
            <option key={rate} value={rate}>
              {rate}×
            </option>
          ))}
        </Select>
      </div>

      {transcript !== undefined && (
        <TranscriptPanel caption={transcript} videoRef={videoRef} />
      )}
    </div>
  );
}

const SPEEDS = [0.75, 1, 1.25, 1.5, 2] as const;

/** Safari plays HLS from a plain <video>; nothing else does without a library. */
function isNativeHlsSupported(): boolean {
  if (typeof document === "undefined") return false;

  const probe = document.createElement("video");

  return probe.canPlayType("application/vnd.apple.mpegurl") !== "";
}

/**
 * Whether hls.js could run here — checked without importing it.
 *
 * The same condition the library's own `isSupported()` turns on, asked
 * synchronously so the «unsupported» message is decided during render rather than
 * flashing a player that then disappears.
 */
function isMseSupported(): boolean {
  return typeof window !== "undefined" && "MediaSource" in window;
}
