"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, TextField } from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { bank, type BankQuestion } from "@/lib/bank";
import { userMessage } from "@/lib/errors";
import { counted } from "@/lib/labels";

type Draft = { key: number; label: string; max_points: string };

/** More than this and the server refuses the list (`criteria` · `max:20`). */
const MAX_CRITERIA = 20;

/**
 * The mark scheme for one essay question (spec 008 · FR-028).
 *
 * ⚠️ THE WHOLE LIST IS SENT ON EVERY SAVE, AND AN EMPTY LIST CLEARS IT. A partial
 * edit cannot say «remove the second criterion», and the server's rule is about
 * the SUM of the set — so the set is the unit, here as on the wire.
 *
 * ⚠️ THE SUM IS SHOWN BEFORE IT IS REFUSED. The server rejects criteria adding up
 * to more than the question is worth (a student would otherwise score 120% of a
 * paper); showing the running total is what saves the teacher discovering that
 * after typing every row. The server's refusal still lands as a sentence — and so
 * does the other one it can give: a scheme somebody has already graded against is
 * frozen, because every past mark points at its criteria by id.
 */
export function RubricEditor({ question }: { question: BankQuestion }) {
  const [rows, setRows] = useState<Draft[]>(() =>
    (question.rubric_criteria ?? []).map((criterion, index) => ({
      key: index,
      label: criterion.label,
      max_points: String(criterion.max_points),
    })),
  );
  const [nextKey, setNextKey] = useState(() => (question.rubric_criteria ?? []).length);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saved, setSaved] = useState(false);

  const total = rows.reduce((sum, row) => sum + (Number(row.max_points) || 0), 0);
  const over = total > question.points;

  const patch = (key: number, change: Partial<Draft>) => {
    setSaved(false);
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...change } : row)));
  };

  const add = () => {
    setSaved(false);
    setRows((current) => [...current, { key: nextKey, label: "", max_points: "" }]);
    setNextKey((key) => key + 1);
  };

  const remove = (key: number) => {
    setSaved(false);
    setRows((current) => current.filter((row) => row.key !== key));
  };

  const save = async () => {
    setSaving(true);
    setError("");
    setErrors({});
    setSaved(false);

    try {
      await bank.saveRubric(
        question.uuid,
        rows.map((row, index) => ({
          label: row.label.trim(),
          max_points: Number(row.max_points),
          order: index,
        })),
      );
      setSaved(true);
    } catch (err: unknown) {
      const fields = fieldErrors(err);

      if (Object.keys(fields).length > 0) {
        setErrors(fields);
      } else {
        setError(userMessage(err));
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card as="section">
      <h2 className="text-base font-semibold text-ink">معايير التصحيح</h2>
      <p className="mt-1 mb-4 text-sm text-ink-muted">
        قسّم درجة السؤال على معايير يُصحَّح بها كلٌّ على حدة. بلا معايير يُصحَّح السؤال بدرجةٍ واحدة.
        بعد أول تصحيحٍ عليها تُقفَل المعايير.
      </p>

      <div className="space-y-4">
        {error !== "" && <Alert tone="danger" title={error} />}
        {errors.criteria !== undefined && <Alert tone="danger" title={errors.criteria} />}
        {saved && <Alert tone="success" title="حُفظت معايير التصحيح." />}

        {rows.length === 0 && (
          <p className="text-sm text-ink-muted">لا معايير لهذا السؤال — يُصحَّح بدرجةٍ واحدة.</p>
        )}

        {rows.map((row, index) => (
          <div key={row.key} className="grid items-end gap-3 sm:grid-cols-[1fr_8rem_auto]">
            <TextField
              id={`criterion-${row.key}-label`}
              label={`المعيار ${index + 1}`}
              value={row.label}
              onChange={(value) => patch(row.key, { label: value })}
              maxLength={255}
              required
              error={errors[`criteria.${index}.label`]}
            />
            <NumberField
              id={`criterion-${row.key}-points`}
              label="الدرجة"
              value={row.max_points}
              onChange={(value) => patch(row.key, { max_points: value })}
              min={0.25}
              step={0.25}
              required
              error={errors[`criteria.${index}.max_points`]}
            />
            <Button variant="ghost" size="sm" onClick={() => remove(row.key)}>
              احذف <span className="sr-only">{`المعيار ${index + 1}`}</span>
            </Button>
          </div>
        ))}

        <div className="flex flex-wrap items-center gap-3">
          <Button variant="ghost" size="sm" onClick={add} disabled={rows.length >= MAX_CRITERIA}>
            أضف معياراً
          </Button>
          <p className={`text-sm ${over ? "text-danger-ink" : "text-ink-muted"}`}>
            المجموع <bdi>{total}</bdi> من <bdi>{question.points}</bdi>
            {rows.length > 0 &&
              ` · ${counted(rows.length, { one: "معيار واحد", two: "معياران", few: "معايير", many: "معياراً", other: "معيار" })}`}
          </p>
        </div>

        {over && <Alert tone="warning" title="مجموع المعايير أكبر من درجة السؤال — قلّل إحداها قبل الحفظ." />}

        <Button
          onClick={() => void save()}
          loading={saving}
          loadingLabel="جارٍ الحفظ…"
          disabled={over || rows.some((row) => row.label.trim() === "" || !(Number(row.max_points) > 0))}
        >
          احفظ المعايير
        </Button>
      </div>
    </Card>
  );
}
