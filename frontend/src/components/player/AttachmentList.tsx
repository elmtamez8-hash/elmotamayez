"use client";

import { useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ApiError } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { media } from "@/lib/media";

/**
 * The files that come with an item, from the student's side.
 *
 * No URL is carried in this list, and that is the design: every attachment is
 * opened by asking for a grant at the moment the student clicks, and the address
 * that comes back stops working minutes later. A list of ready-made links would
 * be the permanent path FR-034 forbids, arriving through the back door of a
 * convenience.
 *
 * So the button does the asking. One extra round-trip, and no link on the page
 * that outlives the session it was drawn in.
 */
export interface StudentAttachment {
  uuid: string;
  original_filename: string;
  kind: string;
  kind_label: string;
  is_downloadable: boolean;
  is_ready: boolean;
}

export function AttachmentList({
  lessonUuid,
  attachments,
  onStale,
}: {
  lessonUuid: string;
  attachments: StudentAttachment[];
  /**
   * Reads the list again. A file the student was shown can be gone by the time
   * they press «فتح» — a teacher who re-sends the board's PDF REPLACES the old
   * attachment (spec 039 · Q2) — and a 404 with no way out but a reload is what
   * they would otherwise get.
   */
  onStale?: () => Promise<void>;
}) {
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState("");

  if (attachments.length === 0) return null;

  const open = async (attachment: StudentAttachment) => {
    setBusy(attachment.uuid);
    setError("");

    try {
      const grant = await media.requestAssetPlayback(lessonUuid, attachment.uuid);

      /*
        `noopener` alone, and dropping `noreferrer` is the point.

        ⚠️ THIS URL IS A REDIRECT FOR ANY COMMERCIAL PROVIDER, AND THAT PROVIDER
        REFUSES A REQUEST WITH NO `Referer` — measured against a real zone on
        2026-08-18: a correctly signed URL answers 403 without the header and 200
        with any value at all. `noreferrer` suppresses it for the navigation and for
        the redirect that follows, so an attachment stored at the CDN would open a
        vendor error page instead of the file, with nothing in our logs.

        Nothing is lost: `noopener` is the half that matters — it keeps the new tab
        from reaching back through `window.opener` — and the referrer we are
        withholding here is our own origin, from a link we built ourselves.
      */
      window.open(grant.manifest_url, "_blank", "noopener");
    } catch (err: unknown) {
      if (onStale && err instanceof ApiError && (err.status === 404 || err.status === 403)) {
        setError("تغيّر هذا الملف، فحدّثنا القائمة. افتحه من جديد.");
        await onStale().catch(() => undefined);
      } else {
        setError(userMessage(err));
      }
    } finally {
      setBusy(null);
    }
  };

  return (
    <section className="space-y-3">
      <h2 className="text-sm font-semibold text-ink">ملفات مع هذا الدرس</h2>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر فتح الملف">
          {error}
        </Alert>
      )}

      <ul className="space-y-2">
        {attachments.map((attachment) => (
          <li
            key={attachment.uuid}
            className="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface-raised px-3 py-2"
          >
            <span className="text-sm text-ink">{attachment.original_filename}</span>
            <span className="text-xs text-ink-muted">{attachment.kind_label}</span>

            {attachment.is_downloadable ? (
              <Badge tone="info">يمكن تحميله</Badge>
            ) : (
              <Badge tone="neutral">عرض فقط</Badge>
            )}

            <span className="ms-auto">
              {attachment.is_ready ? (
                <Button
                  size="sm"
                  variant="secondary"
                  loading={busy === attachment.uuid}
                  loadingLabel="جارٍ الفتح"
                  onClick={() => void open(attachment)}
                >
                  فتح
                </Button>
              ) : (
                // Entitled, but the file is not ready. A different thing from a
                // refusal, and it says so rather than showing a dead button.
                <span className="text-xs text-ink-muted">قيد التجهيز</span>
              )}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}
