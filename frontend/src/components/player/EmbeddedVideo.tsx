"use client";

import { useState } from "react";

import { api } from "@/lib/api";

/**
 * A lesson hosted at YouTube or Vimeo, played inside our page (032 · FR-012).
 *
 * ⚠️ `src` ARRIVES BUILT AND IS NEVER RE-PARSED HERE. The server made this url
 * from a closed host set; treating the payload as a paste to be understood again
 * in the browser would be a second spelling of the rule, and the failure
 * direction is free text in an `<iframe src>` on a page of ours.
 *
 * ⚠️ NO PLAYER LIBRARY, EVER (FR-016 · SC-007). Nothing of the host's runs on
 * our pages beyond the frame tag itself — which is also why nothing here can
 * know whether the video still exists. The line and the button below are the
 * only sensor there is.
 */
export function EmbeddedVideo({
  embedUrl,
  title,
  report,
}: {
  embedUrl: string;
  title: string;
  /**
   * The course key and lesson uuid to report against. Absent where there is
   * nothing to report to — the teacher's own preview, for one.
   */
  report?: { courseKey: string; lessonUuid: string };
}) {
  const [reported, setReported] = useState(false);
  const [sending, setSending] = useState(false);

  const send = async () => {
    if (report === undefined || sending || reported) return;

    setSending(true);

    try {
      await api.post(
        `/marketplace/courses/${encodeURIComponent(report.courseKey)}` +
          `/lessons/${encodeURIComponent(report.lessonUuid)}/report`,
      );
    } catch {
      /*
       * ⚠️ SWALLOWED ON PURPOSE, WHICH IS THE OPPOSITE OF THIS REPO'S USUAL RULE
       * AND FOLLOWS THE SAME REASONING.
       *
       * The server answers ONE constant 202 whatever it finds — it never says
       * whether the lesson exists or whether anybody was told, because a reply
       * that varied would be an oracle for the whole tree. Surfacing a failure
       * here would hand the reader a distinction the contract exists to withhold,
       * and there is nothing they could do with it either way.
       */
    } finally {
      setSending(false);
      setReported(true);
    }
  };

  return (
    <div className="space-y-3">
      <div className="relative w-full overflow-hidden rounded-lg bg-ink/90 pt-[56.25%]">
        <iframe
          src={embedUrl}
          title={title}
          loading="lazy"
          /*
           * ⚠️ `sandbox` WITHOUT `allow-top-navigation`, and that omission is the
           * load-bearing half: without it a frame on a public page of ours can
           * redirect the whole tab, so a visitor who clicked our domain lands on
           * an imitation payment screen with our address in their history.
           *
           * `allow-same-origin` is required for the host's own storage, and it
           * is safe here precisely because the frame's origin is the host's, not
           * ours.
           */
          sandbox="allow-scripts allow-same-origin allow-presentation"
          /*
           * Completes the `youtube-nocookie` decision instead of undoing it. A
           * full referrer would hand the host the exact lesson page a visitor
           * who was asked for nothing is reading.
           */
          referrerPolicy="strict-origin"
          /*
           * ⚠️ THE NARROWEST SET THAT PLAYS A VIDEO. `clipboard-write` and
           * `gyroscope` were copied into the two shipped frames from a vendor's
           * snippet; a frame we did not write gets nothing it does not need.
           */
          allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
          allowFullScreen
          className="absolute inset-0 h-full w-full border-0"
        />
      </div>

      {/*
        ⚠️ ALWAYS VISIBLE, NEVER CONDITIONAL ON A DETECTION WE DO NOT HAVE
        (FR-017). The host answers a valid response and writes its own message
        inside its own frame; the browser forbids reading across origins. So
        there is no state in which this line "appears" — claiming otherwise would
        be a detector that does not exist, and the reader would take its silence
        for proof the video is fine.
      */}
      <p className="flex flex-wrap items-center gap-2 text-xs text-ink-muted">
        <span>هذه الحصّة مستضافة خارج المنصّة عند المدرّس.</span>

        {report !== undefined &&
          (reported ? (
            /*
             * ⚠️ IT THANKS AND DOES NOT PROMISE — the same sentence the server
             * sends, and for the same reason. «أبلغنا المدرّس» is a lie in the
             * duplicate branch and in the no-such-thing branch, and the reader
             * cannot tell which one they are in.
             */
            <span className="text-secondary-ink">شكراً لك. سُجِّلت ملاحظتك.</span>
          ) : (
            <button
              // ⚠️ `type="button"`: a bare button inside a form defaults to
              // submit, and this component may well end up inside one.
              type="button"
              onClick={() => void send()}
              disabled={sending}
              className="underline decoration-dotted underline-offset-2 hover:text-ink"
            >
              الفيديو لا يعمل
            </button>
          ))}
      </p>
    </div>
  );
}
