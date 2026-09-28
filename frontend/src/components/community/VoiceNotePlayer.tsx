"use client";

import { useEffect, useRef, useState } from "react";

import { PauseIcon, PlayIcon } from "@/components/icons";

/**
 * A voice note's player: play/pause, a seek bar, and «0:03 / 0:07».
 *
 * ⚠️ THE LENGTH COMES FROM THE SERVER UNTIL THE BROWSER KNOWS IT. A voice note
 * recorded by Chrome's `MediaRecorder` is a WebM with NO duration in its header,
 * so `audio.duration` is `Infinity` (or `NaN`) until the file has been played to
 * its end — and the browser's own `<audio controls>` showed «0:00 / 0:00» on
 * every note until somebody pressed play (live two-account test, 2026-09-28).
 * The server already stores `duration_seconds` from the recorder, so that is the
 * total until the element can say better. The «seek to 1e101 on
 * `loadedmetadata`» trick was not taken: it downloads the whole file on every
 * bubble that scrolls past, which is exactly what `preload="metadata"` avoids.
 */
export function VoiceNotePlayer({
  url,
  durationSeconds,
  mine,
}: {
  url: string;
  durationSeconds: number | null;
  mine: boolean;
}) {
  const audio = useRef<HTMLAudioElement>(null);
  const [playing, setPlaying] = useState(false);
  const [current, setCurrent] = useState(0);
  const [mediaDuration, setMediaDuration] = useState(Number.NaN);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    const element = audio.current;

    if (element === null) return;

    const onDuration = () => setMediaDuration(element.duration);
    const onTime = () => setCurrent(element.currentTime);
    const onPlay = () => setPlaying(true);
    const onPause = () => setPlaying(false);
    const onEnded = () => {
      setPlaying(false);
      // By now the browser has read the whole file and knows the real length.
      setMediaDuration(element.duration);
      setCurrent(0);
    };
    const onError = () => {
      setPlaying(false);
      setFailed(true);
    };

    element.addEventListener("loadedmetadata", onDuration);
    element.addEventListener("durationchange", onDuration);
    element.addEventListener("timeupdate", onTime);
    element.addEventListener("play", onPlay);
    element.addEventListener("pause", onPause);
    element.addEventListener("ended", onEnded);
    element.addEventListener("error", onError);

    return () => {
      element.removeEventListener("loadedmetadata", onDuration);
      element.removeEventListener("durationchange", onDuration);
      element.removeEventListener("timeupdate", onTime);
      element.removeEventListener("play", onPlay);
      element.removeEventListener("pause", onPause);
      element.removeEventListener("ended", onEnded);
      element.removeEventListener("error", onError);
    };
  }, []);

  const total = knownDuration(mediaDuration, durationSeconds);
  // A server length rounded down can be a fraction shorter than the file.
  const shownTotal = total === null ? null : Math.max(total, current);

  const toggle = () => {
    const element = audio.current;

    if (element === null) return;

    if (!element.paused) {
      element.pause();

      return;
    }

    setFailed(false);
    // A refused play (an expired link, a file the browser cannot decode) is
    // said on the bubble, not swallowed.
    void Promise.resolve(element.play()).catch(() => {
      setPlaying(false);
      setFailed(true);
    });
  };

  const seek = (value: number) => {
    const element = audio.current;

    if (element === null) return;

    element.currentTime = value;
    setCurrent(value);
  };

  const clock = `${formatClock(current)} / ${shownTotal === null ? "—" : formatClock(shownTotal)}`;

  return (
    <div className="flex w-56 max-w-full items-center gap-2">
      {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
      <audio ref={audio} preload="metadata" src={url} className="hidden" />

      <button
        type="button"
        onClick={toggle}
        aria-label={playing ? "إيقاف الرسالة الصوتية مؤقتاً" : "تشغيل الرسالة الصوتية"}
        className={
          "flex h-9 w-9 shrink-0 items-center justify-center rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 " +
          (mine
            ? "bg-primary-soft text-primary-ink focus-visible:outline-white"
            : "bg-primary text-white focus-visible:outline-primary")
        }
      >
        {playing ? <PauseIcon /> : <PlayIcon />}
      </button>

      <div className="min-w-0 flex-1">
        <input
          type="range"
          min={0}
          max={shownTotal ?? 0}
          step={0.1}
          value={Math.min(current, shownTotal ?? 0)}
          disabled={shownTotal === null}
          onChange={(event) => seek(Number(event.target.value))}
          aria-label="موضع التشغيل"
          aria-valuetext={clock}
          className={"block w-full " + (mine ? "accent-white" : "accent-primary")}
        />

        <span className={mine ? "text-[10px] text-white/70" : "text-[10px] text-ink-muted"}>
          {failed ? "تعذّر تشغيل الرسالة الصوتية." : <bdi dir="ltr">{clock}</bdi>}
        </span>
      </div>
    </div>
  );
}

/** The element's own length when it has a finite one, the server's otherwise. */
export function knownDuration(mediaDuration: number, serverSeconds: number | null): number | null {
  if (Number.isFinite(mediaDuration) && mediaDuration > 0) return mediaDuration;

  if (serverSeconds !== null && serverSeconds > 0) return serverSeconds;

  return null;
}

/** `m:ss`, as every player writes it. */
export function formatClock(seconds: number): string {
  const whole = Math.max(0, Math.floor(Number.isFinite(seconds) ? seconds : 0));

  return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, "0")}`;
}
