"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { freezePeriods, type FreezePeriod, type FreezeResult } from "@/lib/class-sessions";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";

/**
 * Holiday freezes.
 *
 * Reached from /manage/sessions, with no nav entry of its own: freezing is an
 * action on the calendar rather than a section of the product.
 *
 * The result of creating one is shown in full — which sessions were suspended
 * and how many seat holders were told. A freeze that quietly takes twelve booked
 * hours away is the failure this screen exists to prevent, and a teacher has to
 * see the cost of the button they just pressed.
 */
export default function ManageFreezePage() {
  const [periods, setPeriods] = useState<FreezePeriod[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const [startsOn, setStartsOn] = useState("");
  const [endsOn, setEndsOn] = useState("");
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<FreezeResult | null>(null);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    freezePeriods
      .list()
      .then((response) => setPeriods(response.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const create = async () => {
    setSaving(true);
    setError("");
    setErrors({});
    setResult(null);

    try {
      setResult(
        await freezePeriods.create({
          starts_on: startsOn,
          ends_on: endsOn,
          reason: reason === "" ? undefined : reason,
        }),
      );
      load();
    } catch (err: unknown) {
      setErrors(fieldErrors(err));
      setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const lift = async (uuid: string) => {
    setError("");

    try {
      await freezePeriods.remove(uuid);
      load();
    } catch (err: unknown) {
      setError(userMessage(err));
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/manage/sessions"
          className="rounded text-sm text-ink-muted hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          ← حصصي
        </Link>
        <h2 className="mt-1 text-2xl font-bold text-ink">فترات التجميد</h2>
      </div>

      <Card>
        <h3 className="mb-2 font-semibold text-ink">تجميد فترة</h3>
        <p className="mb-4 text-sm text-ink-muted">
          خلال الفترة لا تُجدول حصص جديدة ولا يُحتسب غياب ولا يتقدّم أي عدّاد، وتُعلَّق الحصص
          المحجوزة داخلها مع إبلاغ من حجز مقعده.
        </p>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <TextField
            id="starts_on"
            label="من تاريخ"
            type="date"
            value={startsOn}
            onChange={setStartsOn}
            error={errors.starts_on}
          />
          <TextField
            id="ends_on"
            label="إلى تاريخ"
            type="date"
            value={endsOn}
            onChange={setEndsOn}
            error={errors.ends_on}
          />
          <TextField
            id="reason"
            label="السبب"
            value={reason}
            onChange={setReason}
            error={errors.reason}
            placeholder="إجازة نصف العام"
          />
        </div>

        <div className="mt-4">
          <Button onClick={create} loading={saving} disabled={startsOn === "" || endsOn === ""}>
            تجميد الفترة
          </Button>
        </div>

        {error !== "" && (
          <div className="mt-4">
            <Alert tone="danger" title="تعذّر التجميد">
              {error}
            </Alert>
          </div>
        )}

        {result !== null && (
          <div className="mt-4 space-y-3">
            <Alert tone="success" title="جُمّدت الفترة">
              أُبلغ <bdi>{result.notified}</bdi> من أصحاب المقاعد.
            </Alert>

            {result.suspended.length > 0 && (
              <Alert tone="warning" title="حصص عُلِّقت">
                <ul className="space-y-1">
                  {result.suspended.map((session) => (
                    <li key={session.uuid}>
                      {session.title} — <bdi>{session.starts_at}</bdi>
                    </li>
                  ))}
                </ul>
              </Alert>
            )}
          </div>
        )}
      </Card>

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : periods.length === 0 ? (
        <EmptyState
          title="لا فترات تجميد"
          description="جمّد فترة إجازة لتتوقّف الجدولة والعدّادات خلالها."
        />
      ) : (
        <ul className="space-y-2">
          {periods.map((period) => (
            <li
              key={period.uuid}
              className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3"
            >
              <div className="min-w-0">
                <p className="text-sm font-medium text-ink">
                  <bdi>{period.starts_on}</bdi> — <bdi>{period.ends_on}</bdi>
                </p>
                <p className="text-xs text-ink-muted">
                  {period.reason ?? "بلا سبب مذكور"}
                  {period.student != null && ` · ${period.student.name} وحده`}
                </p>
              </div>

              <Button size="sm" variant="secondary" onClick={() => void lift(period.uuid)}>
                رفع التجميد
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
