"use client";

import { type FormEvent, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextareaField, TextField } from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { compliance } from "@/lib/compliance";
import { userMessage } from "@/lib/errors";

/**
 * The public door for reporting a data breach (spec 013 · FR-040).
 *
 * ⚠️ `POST /privacy/breach-reports` EXISTED WITH NOTHING CALLING IT. The route is
 * deliberately unauthenticated — the best-known leaks are reported by outside
 * researchers who hold no account — and with no form anywhere, that reasoning
 * protected a door nobody could find.
 *
 * ⚠️ THE CONFIRMATION IS A CONSTANT, WRITTEN HERE. The server already answers every
 * accepted report with the same sentence so the route is never an oracle
 * (docs/gotchas/compliance.md); this form does not read that body at all, so the
 * screen stays constant even if the body ever stops being. No reference number,
 * no «we already know about this» — either would tell a stranger what we hold.
 *
 * What CAN differ is the reporter's own mistake: a description too short to act on
 * lands under its field (422, the server's Arabic sentence). Everything else — the
 * limiter's 429, a 500, no network — goes through `userMessage()`, never the raw
 * text.
 */
export const BREACH_REPORT_RECEIVED = "وصلنا بلاغك وسيُفحص. شكراً لك.";

export function BreachReportForm() {
  const [description, setDescription] = useState("");
  const [contact, setContact] = useState("");
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [failure, setFailure] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (sending) return;

    setSending(true);
    setErrors({});
    setFailure(null);

    const trimmedContact = contact.trim();

    try {
      await compliance.reportBreach({
        description: description.trim(),
        ...(trimmedContact === "" ? {} : { reporter_contact: trimmedContact }),
      });

      setSent(true);
      setDescription("");
      setContact("");
    } catch (err) {
      const byField = fieldErrors(err);

      if (Object.keys(byField).length > 0) {
        setErrors(byField);
      } else {
        setFailure(userMessage(err));
      }
    } finally {
      setSending(false);
    }
  }

  if (sent) {
    return (
      <div className="space-y-3">
        <Alert tone="success" title={BREACH_REPORT_RECEIVED} />
        <Button variant="ghost" size="sm" onClick={() => setSent(false)}>
          إرسال بلاغ آخر
        </Button>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      {failure && <Alert tone="danger" title={failure} />}

      <TextareaField
        id="description"
        label="ماذا رأيت؟"
        hint="صف ما لاحظته وأين: رابط، صفحة، أو طريقة الوصول. عشرون حرفاً على الأقل."
        value={description}
        onChange={setDescription}
        rows={5}
        required
        error={errors.description}
      />

      <TextField
        id="reporter_contact"
        label="وسيلة للتواصل معك (اختياريّ)"
        hint="بريد أو رقم هاتف إن أردت أن نعود إليك. يمكنك الإبلاغ دون ذكر أيّ منهما."
        value={contact}
        onChange={setContact}
        autoComplete="email"
        error={errors.reporter_contact}
      />

      <Button type="submit" loading={sending} loadingLabel="جارٍ الإرسال…">
        أرسل البلاغ
      </Button>
    </form>
  );
}
