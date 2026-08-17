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
 * What does NOT change is where the manifest comes from. The player still asks our
 * own `/playback/{grant}/stream`, which answers 302 — so every playlist fetch is a
 * fresh trip through the grant, exactly as every byte-range request was.
 */
export function VideoPlayer({ grant }: { grant: PlaybackGrant }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [error, setError] = useState("");
  const [source, setSource] = useState(grant.manifest_url);
  const [speed, setSpeed] = useState(1);

  // Three ways a browser can play what we are about to hand it, and only the
  // third needs a library.
  const usesLibrary = grant.format === "hls" && !isNativeHlsSupported();
  const canPlay =
    grant.format === "progressive" || isNativeHlsSupported() || isMseSupported();

  // The default track is the one worth reading as prose; a translation of the
  // same lesson would repeat it.
  const transcript = grant.captions.find((caption) => caption.is_default) ?? grant.captions[0];

  // Renewal belongs to the watermark, not here — see Watermark.tsx. This
  // component only reacts to what that loop reports.
  const onRenewed = useCallback((manifestUrl: string) => setSource(manifestUrl), []);
  const onStopped = useCallback((message: string) => setError(message), []);

  // Pick up where they left off (FR-036).
  useEffect(() => {
    const video = videoRef.current;
    const resume = grant.resume_at_seconds;

    // Finite check, not just > 0: a missing field arrives as undefined, and
    // assigning that to currentTime throws — which took the whole page down with
    // a client-side exception rather than merely starting from zero.
    if (video === null || !Number.isFinite(resume) || resume <= 0) return;

    video.currentTime = resume;
  }, [grant.resume_at_seconds]);

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

      const hls = new Hls();
      instance = hls;

      // Only the errors the library itself calls fatal. It recovers from the rest
      // on its own, and surfacing those would put a banner over a lesson that is
      // still playing.
      hls.on(Hls.Events.ERROR, (_event, data) => {
        if (data.fatal) setError("تعذّر تشغيل الفيديو. حدّث الصفحة وحاول مجدداً.");
      });

      hls.loadSource(source);
      hls.attachMedia(video);
    })();

    return () => {
      cancelled = true;
      instance?.destroy();
    };
  }, [usesLibrary, source]);

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
          onRenewed={onRenewed}
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
