"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { SelectField, TextField } from "@/components/ui/Field";

/**
 * «أوقف كتابة فلان في هذا النقاش» (021 · FR-047).
 *
 * ⚠️ THE REASON IS REQUIRED BEFORE THE BUTTON ARMS, and that is the requirement
 * rather than a nicety. The student reads it inside the refusal; without one they
 * see a send that fails and retry it until the ban lapses — which is the noise
 * the instrument exists to stop.
 *
 * ⚠️ AND THE CONFIRMATION IS `ConfirmButton`, THE TWO-PRESS ARM. This control
 * sits in a message row a few pixels from «أبلِغ» at a 40px target on a phone,
 * and one of the two is undone in a tap while this one silences a paying student
 * mid-lesson. There is no modal in `components/ui/`, and inventing one for a
 * one-word question means a focus trap, a scroll lock and an escape handler;
 * `window.confirm` is untranslated on some Arabic Android builds and freezes the
 * page mid-lesson. The disarm timer inside it is the load-bearing half — without
 * it a half-pressed control lies in wait for the next stray tap.
 */

/**
 * The five the teacher actually reaches for.
 *
 * ⚠️ «حتى أرفعه بنفسي» IS HERE ONLY BECAUSE «رفع الإيقاف» IS. It was deliberately
 * withheld while `writeBans.lift` had no screen: an open-ended ban whose only
 * exit is a request typed by hand is a control with no way out, which is the
 * «classified by absence» shape this tree records. The two ship together or
 * neither does — remove the lift button and this option has to go with it.
 *
 * The empty string is the wire value for «open», because that is what
 * `writeBans.set` turns into an omitted `minutes` — a `0` there would be a
 * duration the server reads as a validation error.
 */
const DURATIONS: Array<{ value: string; label: string }> = [
  { value: "10", label: "١٠ دقائق" },
  { value: "30", label: "٣٠ دقيقة" },
  { value: "60", label: "ساعة" },
  { value: "1440", label: "يوم" },
  { value: "", label: "حتى أرفعه بنفسي" },
];

export function SilenceControl({
  name,
  busy = false,
  onSilence,
  onLift,
  onCancel,
}: {
  name: string;
  busy?: boolean;
  onSilence: (reason: string, minutes: number | null) => void;
  /** Ends whatever ban this person is under — idempotent on the server. */
  onLift: () => void;
  onCancel: () => void;
}) {
  const [reason, setReason] = useState("");
  const [duration, setDuration] = useState("10");

  const trimmed = reason.trim();

  return (
    <div className="space-y-3 rounded-2xl border border-line bg-surface p-4">
      <p className="text-sm text-ink">
        إيقاف كتابة <span className="font-semibold">{name}</span> في هذا النقاش وحده.
      </p>

      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-52 grow">
          <TextField
            id="silence-reason"
            label="السبب (يقرؤه الطالب)"
            value={reason}
            onChange={setReason}
          />
        </div>

        <div className="w-44">
          <SelectField
            id="silence-duration"
            label="المدّة"
            value={duration}
            onChange={setDuration}
            options={DURATIONS}
          />
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {/*
          Disabled until there is a reason, rather than armed and then refused by
          the server: a control that arms and fails teaches the teacher that the
          button is unreliable.
        */}
        {trimmed === "" ? (
          <Button variant="danger" size="sm" disabled>
            أوقف الكتابة
          </Button>
        ) : (
          <ConfirmButton
            variant="danger"
            size="sm"
            loading={busy}
            confirmLabel="تأكيد الإيقاف"
            onConfirm={() => onSilence(trimmed, duration === "" ? null : Number(duration))}
          >
            أوقف الكتابة
          </ConfirmButton>
        )}

        {/*
          ⚠️ THE EXIT, AND IT NEEDS NO REASON AND NO ARMING. A lift restores
          something, so a two-press confirmation here would be ceremony over the
          one action in this panel that undoes harm rather than doing it — and it
          is idempotent on the server, so pressing it for somebody under no ban
          costs a request and changes nothing.
        */}
        <Button variant="secondary" size="sm" loading={busy} onClick={onLift}>
          رفع الإيقاف
        </Button>

        <Button variant="ghost" size="sm" onClick={onCancel}>
          إلغاء
        </Button>
      </div>
    </div>
  );
}
