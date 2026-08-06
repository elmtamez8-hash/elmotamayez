"use client";

import { useEffect, useState } from "react";

import { type Caption } from "@/lib/media";

/**
 * The lesson's words, read from the same WebVTT the `<track>` element uses.
 *
 * Parsed in the browser from the file already being fetched — there is no
 * transcript column and no transcript endpoint. A stored second copy would drift
 * from the subtitles the first time someone fixed a typo in one of them.
 *
 * Its real job is not reading: it is finding. Clicking a line seeks the video,
 * which is how someone returns to the one minute of a fifty-minute lesson they
 * actually needed.
 */
export function TranscriptPanel({
  caption,
  videoRef,
}: {
  caption: Caption;
  videoRef: React.RefObject<HTMLVideoElement | null>;
}) {
  const [cues, setCues] = useState<Cue[] | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let cancelled = false;

    fetch(caption.url)
      .then((res) => (res.ok ? res.text() : Promise.reject(new Error("caption"))))
      .then((text) => {
        if (!cancelled) setCues(parseVtt(text));
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });

    return () => {
      cancelled = true;
    };
  }, [caption.url]);

  if (failed || cues === null || cues.length === 0) return null;

  return (
    <details className="rounded-xl border border-line bg-surface-raised">
      <summary className="cursor-pointer rounded-xl px-4 py-3 font-semibold text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
        النصّ المصاحب
      </summary>

      <ol className="max-h-80 space-y-1 overflow-y-auto px-2 pb-3">
        {cues.map((cue, index) => (
          <li key={`${cue.start}-${index}`}>
            <button
              type="button"
              onClick={() => {
                const video = videoRef.current;
                if (video === null) return;
                video.currentTime = cue.start;
              }}
              className="flex w-full items-start gap-3 rounded-lg px-2 py-1 text-start text-sm text-ink hover:bg-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              <span dir="ltr" className="shrink-0 font-mono text-xs text-ink-muted">
                {clock(cue.start)}
              </span>
              <span>{cue.text}</span>
            </button>
          </li>
        ))}
      </ol>
    </details>
  );
}

type Cue = { start: number; text: string };

/**
 * Enough WebVTT to list the cues and know where each begins.
 *
 * Positioning, regions and styling are ignored on purpose: the `<track>`
 * element renders those over the video, and this list is prose.
 */
function parseVtt(content: string): Cue[] {
  return content
    .replace(/\r\n/g, "\n")
    .split("\n\n")
    .flatMap((block) => {
      const lines = block.split("\n").map((line) => line.trim()).filter(Boolean);
      const timingAt = lines.findIndex((line) => line.includes("-->"));

      if (timingAt === -1) return [];

      const text = lines.slice(timingAt + 1).join(" ").replace(/<[^>]*>/g, "").trim();

      if (text === "") return [];

      return [{ start: seconds(lines[timingAt].split("-->")[0].trim()), text }];
    });
}

/** `01:02:03.500` or `02:03.500` → seconds. */
function seconds(stamp: string): number {
  const parts = stamp.split(":").map(Number);

  return parts.reduce((total, part) => total * 60 + part, 0);
}

function clock(total: number): string {
  const minutes = Math.floor(total / 60);
  const rest = Math.floor(total % 60);

  return `${minutes}:${String(rest).padStart(2, "0")}`;
}
