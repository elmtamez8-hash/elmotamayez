"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { classSessions, type BookingStatus } from "@/lib/class-sessions";
import { userMessage } from "@/lib/errors";
import { formatSessionDay, formatSessionClock } from "@/lib/session-format";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";

/**
 * «إلغاء الحجز» — the student's own way out of a seat.
 *
 * ⚠️ `DELETE /bookings/{uuid}` HAD NO CALLER. `lib/class-sessions.ts` carried
 * `cancelBooking` and nothing pressed it, so a student who could not make a
 * lesson had no way to say so: they were marked absent from a session they would
 * have told us about, and — inside the window — charged for a seat they could
 * have handed back for free.
 *
 * ⚠️ THE COST IS SAID BEFORE THE PRESS, NOT AFTER. A late cancellation is
 * allowed and STILL CHARGED (FR-010: the deadline is what the seat costs after it
 * passes, not a lock on the button), so a confirm that only asks «متأكد؟» lets
 * somebody give up a paid seat without learning it stays paid. The sentence is a
 * PREDICTION from `may_cancel_until`; the server decides at submit, and the
 * status label it answers with is what this component shows afterwards.
 *
 * A `Modal`, not `ConfirmButton`: this is asked while nothing else is happening,
 * never mid-lesson (CLAUDE.md draws the line at the moment, not the severity).
 */
export function CancelBookingButton({
  bookingUuid,
  mayCancelUntil,
  timezone,
  onCancelled,
  now = () => Date.now(),
}: {
  bookingUuid: string;
  mayCancelUntil: string;
  /** Tests only — every screen draws the deadline on the viewer's own clock. */
  timezone?: string;
  onCancelled: (result: { status: BookingStatus; status_label: string }) => void;
  /** A parameter so a test can say what time it is instead of arranging one. */
  now?: () => number;
}) {
  const viewerZone = useViewerTimeZone();
  const zone = timezone ?? viewerZone;
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [refusal, setRefusal] = useState<string | null>(null);

  const free = now() < Date.parse(mayCancelUntil);
  const deadline = `${formatSessionDay(mayCancelUntil, zone)} ${formatSessionClock(mayCancelUntil, zone)}`;

  const message = free
    ? `الإلغاء مجاني حتى ${deadline}: يعود المقعد لغيرك ولا تُحتسب عليك الحصة.`
    : `انتهت مهلة الإلغاء المجاني (${deadline}). إن ألغيت الآن تبقى الحصة محتسبة عليك، ولا يعود المقعد.`;

  const cancel = async () => {
    setBusy(true);
    setRefusal(null);

    try {
      const booking = await classSessions.cancelBooking(bookingUuid);
      setAsking(false);
      onCancelled({ status: booking.status, status_label: booking.status_label });
    } catch (err) {
      setAsking(false);
      setRefusal(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col items-end gap-1">
      <Button size="sm" variant="ghost" onClick={() => setAsking(true)}>
        إلغاء الحجز
      </Button>

      {refusal !== null && (
        <p role="alert" className="text-xs text-danger-ink">
          {refusal}
        </p>
      )}

      <Modal
        open={asking}
        title={free ? "إلغاء الحجز" : "إلغاء متأخّر"}
        message={message}
        confirmLabel={free ? "ألغِ الحجز" : "ألغِ مع احتسابها"}
        tone="danger"
        busy={busy}
        onConfirm={() => void cancel()}
        onCancel={() => setAsking(false)}
      />
    </div>
  );
}
