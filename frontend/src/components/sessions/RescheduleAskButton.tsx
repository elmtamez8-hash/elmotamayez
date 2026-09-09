"use client";

import { useState } from "react";

import { ScheduleIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextField, TextareaField } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { userMessage } from "@/lib/errors";
import { localDateTimeToIso } from "@/lib/labels";
import { rescheduleRequests } from "@/lib/reschedule-requests";

/**
 * «أعتذر عن حصّة السبت — هل يمكن الأحد ٦م؟»
 *
 * ⚠️ A MODAL, NOT A `ConfirmButton`. The line this product draws is the MOMENT,
 * not the severity: a two-press arm belongs on a control pressed mid-lesson,
 * where a window covering the screen is itself the harm. This is asked while
 * looking at a timetable with nothing else happening, and it needs two fields —
 * which a button cannot hold at all.
 *
 * ⚠️ AND NOTHING IS PROMISED. The copy says the teacher must agree, because a
 * screen that reads «تم التأجيل» over a request nobody has answered is a student
 * who does not turn up on Saturday.
 */
export function RescheduleAskButton({
  sessionUuid,
  title,
  onDone,
}: {
  sessionUuid: string;
  title: string;
  onDone?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [when, setWhen] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  const submit = () => {
    setBusy(true);
    setError(null);

    rescheduleRequests
      // ⚠️ The naive wall clock IS the defect: a `datetime-local` value carries
      // no zone and the API runs on UTC, so sending it raw books an hour that
      // is right only for a reader in the same offset as the server.
      .ask(sessionUuid, localDateTimeToIso(when), reason.trim() === "" ? undefined : reason.trim())
      .then(() => {
        setSent(true);
        setOpen(false);
        setWhen("");
        setReason("");
        onDone?.();
      })
      // A 422 here names something the student can act on — «ليس لك مقعد»,
      // «هناك طلب قائم» — so it is printed rather than replaced with «تعذّر».
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setBusy(false));
  };

  return (
    <>
      {sent ? (
        <p className="text-xs text-ink-muted">طلب التأجيل بانتظار ردّ المدرّس.</p>
      ) : (
        <Button variant="ghost" size="sm" onClick={() => setOpen(true)}>
          <ScheduleIcon className="h-4 w-4" />
          اطلب تأجيلها
        </Button>
      )}

      <Modal
        open={open}
        title={`طلب تأجيل «${title}»`}
        message="المدرّس هو من يوافق. الحصة التالية تبقى في موعدها المعتاد مهما كان الردّ."
        confirmLabel="أرسِل الطلب"
        busy={busy}
        onConfirm={submit}
        onCancel={() => {
          setOpen(false);
          setError(null);
        }}
      >
        <div className="mt-4 space-y-4">
          {error !== null && (
            <Alert tone="danger" title="لم يُرسَل الطلب">
              {error}
            </Alert>
          )}

          <TextField
            id="reschedule-when"
            label="الموعد المقترح"
            type="datetime-local"
            value={when}
            onChange={setWhen}
            required
          />

          <TextareaField
            id="reschedule-reason"
            label="السبب"
            hint="يقرؤه المدرّس قبل أن يقرّر."
            value={reason}
            onChange={setReason}
          />
        </div>
      </Modal>
    </>
  );
}
