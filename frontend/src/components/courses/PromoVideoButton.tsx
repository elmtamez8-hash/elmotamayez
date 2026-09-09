"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";

/**
 * The course's promotional video, revealed by a button (018 · FR-013).
 *
 * ⚠️ THE `<iframe>` IS NOT IN THE DOM UNTIL THE BUTTON IS PRESSED, and that is
 * the whole point rather than a detail. An iframe mounted with the page loads a
 * third party's scripts and cookies for EVERY visitor to EVERY course page, and
 * most of them will never press play — a privacy and performance bill paid for
 * nothing. Hiding it with `hidden` or `display:none` looks identical on screen
 * and loads exactly the same bytes; `PromoVideoButton.test.tsx` asserts the
 * absence for that reason, and it is the one assertion here that cannot go.
 *
 * ⚠️ AND `src` IS BUILT FROM THE ID, NEVER FROM ANYTHING PASTED (FR-008). The
 * host is a constant in this file and the id is url-encoded on the way in, so a
 * user-supplied string cannot reach the frame even if the server's extractor
 * were one day loosened. The server stores only the extracted id; this is the
 * second lock on the same door, and it costs one function call.
 *
 * Revealed in place rather than in an overlay. ⚠️ The upgrade path this comment
 * named — a native `<dialog>` — was taken in spec 033, so `components/ui/Modal`
 * exists and the focus trap and scroll lock it used to price are the browser's.
 * It is still not used here, for a different reason: a video is CONTENT, not a
 * question. A window demands an answer and takes the page away until it gets
 * one; a promo clip is something a visitor glances at and scrolls past, and
 * putting it behind an Escape key would be the only way out of a video that
 * needs no way out.
 *
 * There is deliberately no booking call to action underneath: the course page
 * has carried one since 023, and a second is a duplicate of a live entrance.
 */

// The privacy-preserving embed host. A constant, never a prop and never a value
// from the API: the day it lives in a payload it becomes part of a stored public
// contract that cannot be changed without a version.
const EMBED_HOST = "https://www.youtube-nocookie.com/embed/";

type Props = {
  videoId: string;
  courseTitle: string;
};

export function PromoVideoButton({ videoId, courseTitle }: Props) {
  const [open, setOpen] = useState(false);

  return (
    <div className="space-y-3">
      <Button
        variant="secondary"
        onClick={() => setOpen((wasOpen) => !wasOpen)}
        aria-expanded={open}
      >
        {open ? "إخفاء النموذج" : "شاهد نموذجاً من الشرح"}
      </Button>

      {open ? (
        <div className="overflow-hidden rounded-xl border border-line bg-surface-raised">
          <iframe
            // `rel=0` keeps the end card on this channel rather than sending the
            // visitor into a stranger's recommendations from our own page.
            src={`${EMBED_HOST}${encodeURIComponent(videoId)}?rel=0`}
            // An untitled frame is an unnamed box to a screen reader.
            title={`نموذج من شرح ${courseTitle}`}
            allow="accelerometer; encrypted-media; picture-in-picture; fullscreen"
            allowFullScreen
            className="aspect-video w-full"
          />
        </div>
      ) : null}
    </div>
  );
}
