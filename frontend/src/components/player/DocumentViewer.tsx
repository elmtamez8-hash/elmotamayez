"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { userMessage } from "@/lib/errors";
import { media, type PlaybackGrant } from "@/lib/media";

/**
 * Reading a document inside the platform.
 *
 * Two rules, and the second is the one people get wrong.
 *
 * **A view-only document carries its reader's name** (FR-036). Not to stop a
 * screenshot — nothing stops a screenshot — but so that a copy which does
 * circulate says who it came from. That is a deterrent that works on the person
 * deciding whether to forward it, which is the only place a deterrent can work.
 *
 * **The frame is fed by a grant, not by a path.** The URL inside `src` expires
 * in minutes and is bound to this sign-in, so the address a curious reader
 * copies out of devtools is worthless a few minutes later. That is the whole of
 * what "view only" buys, and the teacher's editor says so in as many words.
 */
export function DocumentViewer({
  lessonUuid,
  assetUuid,
  filename,
}: {
  lessonUuid: string;
  /** Omitted for the item's own file; given for an attachment. */
  assetUuid?: string;
  filename: string;
}) {
  const [grant, setGrant] = useState<PlaybackGrant | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    const request =
      assetUuid === undefined
        ? media.requestPlayback(lessonUuid)
        : media.requestAssetPlayback(lessonUuid, assetUuid);

    request
      .then((result) => {
        if (!cancelled) setGrant(result);
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(userMessage(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [lessonUuid, assetUuid]);

  if (loading) return <p className="text-sm text-ink-muted">جارٍ فتح المستند…</p>;

  if (error !== "" || grant === null) {
    return (
      <Alert tone="danger" title="تعذّر فتح المستند">
        {error || "أعد المحاولة."}
      </Alert>
    );
  }

  return (
    <div className="space-y-2">
      <div className="relative overflow-hidden rounded-xl border border-line">
        <iframe
          src={grant.manifest_url}
          title={filename}
          className="h-[70vh] w-full bg-surface-raised"
        />

        {/*
          Over the frame, not inside it: the document is served by the platform
          and rendered by the browser's own viewer, which we do not control and
          must not try to. `pointer-events-none` so the mark never swallows a
          scroll or a click on the page beneath it.
        */}
        <span
          aria-hidden="true"
          className="pointer-events-none absolute bottom-3 end-3 select-none rounded-lg bg-ink/60 px-2 py-1 text-xs text-surface"
        >
          {/* The server puts the reader's name on the grant; the browser is not
              asked who it thinks it is. */}
          {grant.watermark.name}
        </span>
      </div>

      <p className="text-xs text-ink-muted">
        هذا الرابط مؤقّت ومرتبط بحسابك — يتوقّف عن العمل بعد دقائق، فنسخه لا يفيد غيرك.
      </p>

      {/* Rendered only when the teacher allowed it — and refused by the server
          regardless, which is where the decision actually lives. */}
      <Button href={grant.manifest_url} external size="sm" variant="secondary">
        فتح في نافذة جديدة
      </Button>
    </div>
  );
}
