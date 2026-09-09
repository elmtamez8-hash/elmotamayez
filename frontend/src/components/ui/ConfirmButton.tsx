"use client";

import { useEffect, useRef, useState } from "react";

import { Button } from "@/components/ui/Button";

/** How long the armed state waits before deciding the press was a mistake. */
const DISARM_MS = 4000;

/**
 * A destructive control that asks once, in place.
 *
 * ⚠️ THE PROBLEM IT SOLVES IS A TEACHER'S THUMB. «اكتم الجميع» and «أخرِج
 * الجميع» sit in one wrapping row eight pixels apart, and the per-row «كتم» and
 * «إخراج» are the same distance; one of each pair is recoverable and the other
 * ejects a paying student mid-lesson. «إنهاء الحصة» ends the broadcast for
 * everybody in one tap. None of them could be taken back.
 *
 * ⚠️ AND IT IS NOT A DIALOG — WHICH IS NOW A CHOICE RATHER THAN AN ABSENCE.
 * There IS a modal in `components/ui/` since spec 033 ({@link Modal}, a native
 * `<dialog>`, so the focus trap and the scroll lock this comment used to price
 * are the browser's). It is not used here on purpose: these controls are pressed
 * DURING a live lesson, and a window that covers the screen is the same harm as
 * the `window.confirm` that blocks the page — a teacher cannot watch the class
 * while answering a dialog. The button becomes the question instead: the first
 * press arms it and the label says what the second press will do.
 *
 * The line between the two is WHEN, not how destructive: a window belongs on a
 * question asked while nothing else is happening (deleting a chapter, changing
 * an item's type), and an arm belongs on a control pressed mid-lesson.
 *
 * The disarm timer is what keeps a half-pressed control from lying in wait.
 * Four seconds is long enough to read a short Arabic label and short enough that
 * a teacher who looked away comes back to the safe state.
 */
export function ConfirmButton({
  children,
  confirmLabel,
  onConfirm,
  loading,
  size,
  variant = "danger",
}: {
  children: React.ReactNode;
  /** What the SECOND press will do, said plainly. */
  confirmLabel: string;
  onConfirm: () => void;
  loading?: boolean;
  size?: "sm" | "md" | "lg";
  variant?: "danger" | "secondary" | "ghost";
}) {
  const [armed, setArmed] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(
    () => () => {
      if (timer.current !== null) clearTimeout(timer.current);
    },
    [],
  );

  const press = () => {
    if (armed) {
      if (timer.current !== null) clearTimeout(timer.current);
      setArmed(false);
      onConfirm();

      return;
    }

    setArmed(true);
    timer.current = setTimeout(() => setArmed(false), DISARM_MS);
  };

  return (
    <Button
      variant={armed ? "danger" : variant}
      size={size}
      loading={loading}
      onClick={press}
    >
      {armed ? confirmLabel : children}
    </Button>
  );
}
