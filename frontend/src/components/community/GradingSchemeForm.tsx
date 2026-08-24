"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { NumberField, TextField } from "@/components/ui/Field";
import { arabicNumber } from "@/lib/numerals";
import { GRADE_COMPONENTS } from "@/lib/reviews";

/**
 * How a teacher weights the four grade components (spec 010 · FR-049, FR-050).
 *
 * ⚠️ THE RUNNING TOTAL IS SHOWN AND THE SAVE BUTTON REFUSES BELOW OR ABOVE 100,
 * and neither of those is the guard. `SaveGradingScheme` enforces it in the
 * Action, which is the entry point the seeders and the panel share with this
 * screen — this is only the courtesy of saying so before the round trip, so the
 * teacher can see WHICH way they are out and by how much rather than reading a
 * refusal after submitting.
 *
 * ⚠️ AND A COMPONENT MAY BE WEIGHTED ZERO. That is not the same as a component
 * with no data: zero means "I do not grade on this", and the server excludes it
 * exactly as it excludes one nobody set any work for (FR-053).
 */
export function GradingSchemeForm({
  onSave,
  busy = false,
  error = null,
  initialWeights,
  initialPeriod,
}: {
  onSave: (input: {
    period_start: string;
    period_end: string;
    weights: Record<string, number>;
  }) => void;
  busy?: boolean;
  error?: string | null;
  initialWeights?: Record<string, number>;
  initialPeriod?: { start: string; end: string };
}) {
  const [weights, setWeights] = useState<Record<string, string>>(() =>
    Object.fromEntries(
      GRADE_COMPONENTS.map((component) => [
        component.key,
        String(initialWeights?.[component.key] ?? 25),
      ]),
    ),
  );

  const [start, setStart] = useState(initialPeriod?.start ?? "");
  const [end, setEnd] = useState(initialPeriod?.end ?? "");

  const total = GRADE_COMPONENTS.reduce(
    (sum, component) => sum + (Number(weights[component.key]) || 0),
    0,
  );

  const balanced = total === 100;

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        if (!balanced) return;

        onSave({
          period_start: start,
          period_end: end,
          weights: Object.fromEntries(
            GRADE_COMPONENTS.map((component) => [
              component.key,
              Number(weights[component.key]) || 0,
            ]),
          ),
        });
      }}
      className="space-y-4"
      noValidate
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="period_start"
          label="بداية الفترة"
          type="date"
          value={start}
          onChange={setStart}
          required
          disabled={busy}
        />
        <TextField
          id="period_end"
          label="نهاية الفترة"
          type="date"
          value={end}
          onChange={setEnd}
          required
          disabled={busy}
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        {GRADE_COMPONENTS.map((component) => (
          <NumberField
            key={component.key}
            id={component.key}
            label={component.label}
            value={weights[component.key]}
            onChange={(value) =>
              setWeights((current) => ({ ...current, [component.key]: value }))
            }
            min={0}
            max={100}
            disabled={busy}
          />
        ))}
      </div>

      <p className="text-sm text-ink-muted" data-testid="weights-total">
        المجموع: <strong className="text-ink">{arabicNumber(total)}٪</strong>
      </p>

      {/* The sentence names the gap, not merely the rule: «ينقص ١٠٪» is
          actionable and «المجموع يجب أن يساوي ١٠٠٪» is a restatement of the
          label the teacher is already looking at. */}
      {!balanced && (
        <Alert tone="warning" title="الأوزان غير مكتملة">
          {total < 100
            ? `ينقص ${arabicNumber(100 - total)}٪ لبلوغ المئة.`
            : `يزيد ${arabicNumber(total - 100)}٪ عن المئة.`}
        </Alert>
      )}

      {error && (
        <Alert tone="danger" title="تعذّر الحفظ">
          {error}
        </Alert>
      )}

      <Button type="submit" variant="primary" loading={busy} disabled={!balanced}>
        حفظ الأوزان
      </Button>
    </form>
  );
}
