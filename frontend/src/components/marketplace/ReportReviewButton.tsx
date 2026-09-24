"use client";

import { useEffect, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { TextareaField } from "@/components/ui/Field";
import { api, fieldErrors, hasAuthToken } from "@/lib/api";
import { userMessage } from "@/lib/errors";

/**
 * «إبلاغ» under one public review (spec 010 · FR-034).
 *
 * ⛔ `POST /reviews/{review}/report` SHIPPED WITH THE MODERATION PATH AND NOTHING
 * COULD REACH IT: the public profile published no review uuid, and the tab had
 * no button. A visitor who read an abusive comment about a teacher had nowhere
 * to say so.
 *
 * ⚠️ SIGNED-IN ONLY, AND DECIDED AFTER MOUNT. The route sits under
 * `auth:sanctum`, and the reviews tab is server-rendered for crawlers — the token
 * lives in `localStorage`, which the server render cannot read, so deciding
 * there would ship the wrong branch in the HTML (`ReviewForm`'s reasoning).
 *
 * ⚠️ A REPORT HIDES NOTHING. It files a row for the platform's moderators and
 * the review stays on the profile; the confirmation says exactly that, in the
 * server's own sentence, so nobody reads the button as a way to take down a
 * rating they dislike.
 */
export function ReportReviewButton({ reviewUuid }: { reviewUuid: string }) {
  // null until mounted: see the docblock.
  const [signedIn, setSignedIn] = useState<boolean | null>(null);
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [reasonError, setReasonError] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  useEffect(() => setSignedIn(hasAuthToken()), []);

  if (signedIn !== true) return null;

  if (done !== null) {
    return (
      <p role="status" className="mt-3 text-end text-xs text-ink-muted">
        {done}
      </p>
    );
  }

  const submit = async () => {
    setBusy(true);
    setReasonError(undefined);
    setProblem(null);

    try {
      const trimmed = reason.trim();
      const response = await api.post<{ message?: string }>(
        `/reviews/${reviewUuid}/report`,
        trimmed === "" ? {} : { reason: trimmed },
      );

      setOpen(false);
      setDone(response?.message ?? "وصلنا بلاغك، وسيطّلع عليه فريق المنصّة.");
    } catch (error: unknown) {
      const fields = fieldErrors(error);

      if (fields.reason !== undefined) {
        setReasonError(fields.reason);
      } else {
        setOpen(false);
        setProblem(userMessage(error));
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mt-3 flex flex-col items-end gap-1">
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="rounded text-xs text-ink-muted underline-offset-2 transition hover:text-ink hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        إبلاغ
      </button>

      {problem !== null && (
        <p role="alert" className="text-xs text-danger-ink">
          {problem}
        </p>
      )}

      <Modal
        open={open}
        title="الإبلاغ عن هذا التقييم"
        message="يصل بلاغك إلى فريق المنصّة ليراجعه، ولا يُخفى التقييم بمجرّد الإبلاغ."
        confirmLabel="أرسل البلاغ"
        tone="danger"
        busy={busy}
        onConfirm={() => void submit()}
        onCancel={() => setOpen(false)}
      >
        <TextareaField
          id={`report_reason_${reviewUuid}`}
          label="السبب (اختياري)"
          value={reason}
          onChange={setReason}
          rows={3}
          error={reasonError}
        />
      </Modal>
    </div>
  );
}
