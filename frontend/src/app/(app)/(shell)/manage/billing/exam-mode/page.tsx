"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField } from "@/components/ui/Field";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage, fieldErrors } from "@/lib/api";
import { billing, type ExamModeWindow } from "@/lib/billing";
import { formatDate } from "@/lib/labels";

/**
 * Exam season, when nothing is deferred (FR-046).
 *
 * ⚠️ THE SCREEN HAS NO SWITCH, and that is the design showing through. There is
 * no stored on/off anywhere: a window either covers today or it does not, so the
 * mode returns by itself the morning after the last day — no job to forget, and
 * no flag that could be left on over the summer.
 *
 * ⚠️ AND IT CANCELS NOTHING (FR-047). Opening a window writes one row; seats
 * already taken stay taken. Said on the screen because a teacher who suspects
 * otherwise will not use it during the week it matters most.
 */
export default function ExamModePage() {
  // Named `active`, never `window`: this is a client component, and a state
  // variable by that name shadows the global one for the whole file.
  const [active, setActive] = useState<ExamModeWindow | null>(null);
  const [startsOn, setStartsOn] = useState("");
  const [endsOn, setEndsOn] = useState("");
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    billing
      .examMode()
      .then((res) => setActive(res.data))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const open = async () => {
    setBusy(true);
    setError("");
    setErrors({});

    try {
      const res = await billing.openExamMode(startsOn, endsOn);
      setActive(res.data);
      setStartsOn("");
      setEndsOn("");
    } catch (err: unknown) {
      // 422 lands under its field; everything else becomes one Arabic sentence.
      setErrors(fieldErrors(err));
      setError(errorMessage(err, "تعذّر تفعيل وضع الامتحانات. أعد المحاولة."));
    } finally {
      setBusy(false);
    }
  };

  const close = async () => {
    setBusy(true);
    setError("");

    try {
      await billing.closeExamMode();
      setActive(null);
    } catch (err: unknown) {
      setError(errorMessage(err, "تعذّر إيقاف وضع الامتحانات. أعد المحاولة."));
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <RowsSkeleton />;
  if (failed) return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">وضع الامتحانات</h1>
        <p className="mt-1 text-sm text-ink-muted">
          خلال الفترة التي تحدّدها، لا يُسمح بحجز حصة إلا برصيد كافٍ — أياً كان
          الحد الائتماني الممنوح للطالب.
        </p>
      </header>

      {error !== "" && <Alert tone="danger" title="تعذّر الحفظ">{error}</Alert>}

      {active === null ? (
        <Card as="section">
          <h2 className="text-base font-semibold text-ink">تفعيل الوضع</h2>

          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <TextField
              id="exam-mode-start"
              label="من تاريخ"
              type="date"
              value={startsOn}
              onChange={setStartsOn}
              error={errors.starts_on}
            />
            <TextField
              id="exam-mode-end"
              label="إلى تاريخ"
              type="date"
              value={endsOn}
              onChange={setEndsOn}
              error={errors.ends_on}
            />
          </div>

          <p className="mt-3 text-xs text-ink-muted">
            اليوم الأخير مشمول، ويعود الوضع الطبيعي تلقائياً في اليوم التالي بلا
            أي إجراء منك.
          </p>

          <div className="mt-4">
            <Button
              onClick={open}
              disabled={busy || startsOn === "" || endsOn === ""}
            >
              تفعيل
            </Button>
          </div>
        </Card>
      ) : (
        <Card as="section">
          <Alert tone="warning" title="وضع الامتحانات مفعَّل الآن">
            من <bdi>{formatDate(active.starts_on)}</bdi> إلى{" "}
            <bdi>{formatDate(active.ends_on)}</bdi>. الحجز في هذه الفترة يتطلّب
            رصيداً كافياً، والحصص المحجوزة قبل التفعيل لم تتأثّر.
          </Alert>

          <div className="mt-4">
            <Button variant="secondary" onClick={close} disabled={busy}>
              إيقاف الوضع الآن
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}
