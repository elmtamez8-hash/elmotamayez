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
 * The four the teacher actually reaches for.
 *
 * ⚠️ NO «حتى أرفعه بنفسي» HERE, THOUGH THE SERVER ACCEPTS ONE. An open-ended ban
 * needs a lift button to end it, and there is none — offering the option would
 * ship a control whose only exit is a request typed by hand, which is the
 * «feature classified by absence» this file's own sibling docblock records. Every
 * ban offered here lapses on its own; silence that must last is the workspace
 * ban, which is recorded, appealable, and has a screen.
 */
const DURATIONS: Array<{ value: string; label: string }> = [
  { value: "10", label: "١٠ دقائق" },
  { value: "30", label: "٣٠ دقيقة" },
  { value: "60", label: "ساعة" },
  { value: "1440", label: "يوم" },
];

export function SilenceControl({
  name,
  busy = false,
  onSilence,
  onCancel,
}: {
  name: string;
  busy?: boolean;
  onSilence: (reason: string, minutes: number) => void;
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
            onConfirm={() => onSilence(trimmed, Number(duration))}
          >
            أوقف الكتابة
          </ConfirmButton>
        )}

        <Button variant="ghost" size="sm" onClick={onCancel}>
          إلغاء
        </Button>
      </div>
    </div>
  );
}
