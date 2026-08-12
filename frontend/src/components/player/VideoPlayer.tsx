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
 * What it does not give is HLS outside Safari — and there is no HLS manifest to
 * play yet, because there is no provider producing one. Rather than shipping a
 * player library for a format nothing emits, an unsupported format says so in
 * words instead of showing a black rectangle.
 */
export function VideoPlayer({ grant }: { grant: PlaybackGrant }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [error, setError] = useState("");
  const [source, setSource] = useState(grant.manifest_url);
  const [speed, setSpeed] = useState(1);

  const canPlay = grant.format === "progressive" || isNativeHlsSupported();

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
          src={source}
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
