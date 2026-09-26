"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { TextField } from "@/components/ui/Field";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { ScheduleIcon, SessionsIcon } from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { freezePeriods, type FreezePeriod, type FreezeResult } from "@/lib/class-sessions";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { counted, NOUNS } from "@/lib/labels";

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
  const [liftError, setLiftError] = useState("");
  const [lifted, setLifted] = useState(false);

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
    setLifted(false);
    setLiftError("");

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

  /*
    ⛔ «جُمّدت الفترة» OUTLIVED THE FREEZE IT ANNOUNCED (2026-09-26): lifting a
    period reloaded the list and left the creation banner — and its list of
    suspended sessions — standing above it, reporting a freeze that no longer
    existed. A lift clears it and says what it did; a failed lift says so under
    its own title rather than «تعذّر التجميد», which is the other button.
  */
  const lift = async (uuid: string) => {
    setError("");
    setLiftError("");
    setLifted(false);

    try {
      await freezePeriods.remove(uuid);
      setResult(null);
      setLifted(true);
      load();
    } catch (err: unknown) {
      setLiftError(userMessage(err));
    }
  };

  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Link
          href="/manage/sessions"
          className="rounded text-sm text-ink-muted hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          ← حصصي
        </Link>
        {/* The screen «حصصي» opens it from, so the same icon. */}
        <PageHeader Icon={SessionsIcon} title="فترات التجميد" />
      </div>

      <Card>
        <div className="mb-4">
          <SectionHeading
            id="freeze-new"
            Icon={ScheduleIcon}
            title="تجميد فترة"
            description="خلال الفترة لا تُجدول حصص جديدة ولا يُحتسب غياب ولا يتقدّم أي عدّاد، وتُعلَّق الحصص المحجوزة داخلها مع إبلاغ من حجز مقعده."
          />
        </div>

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
              {/* «أُبلغ 0 من أصحاب المقاعد» shipped: a Latin digit, and a
                  count that no Arabic sentence reads at zero. */}
              {result.notified === 0
                ? "لا أحد يحمل مقعداً في هذه الفترة، فلم يُبلَّغ أحد."
                : `أُبلغ بالتجميد ${counted(result.notified, NOUNS.students)} من أصحاب المقاعد.`}
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

      {lifted && <Alert tone="success" title="رُفع التجميد" />}

      {liftError !== "" && (
        <Alert tone="danger" title="تعذّر رفع التجميد">
          {liftError}
        </Alert>
      )}

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
              className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface-raised p-3 transition-colors duration-200 hover:border-primary/40 hover:bg-primary-soft/30"
            >
              <div className="min-w-0">
                <p className="flex items-center gap-1.5 text-sm font-medium text-ink">
                  <ScheduleIcon className="h-4 w-4 shrink-0 text-ink-muted" />
                  <span>
                    <bdi>{period.starts_on}</bdi> — <bdi>{period.ends_on}</bdi>
                  </span>
                </p>
                <p className="text-xs text-ink-muted">
                  {period.reason ?? "بلا سبب مذكور"}
                  {period.student != null && ` · ${period.student.name} وحده`}
                </p>
              </div>

              {/* Two presses: lifting a freeze moves the paused subscriptions'
                  end dates back and releases seats at once — not undone by
                  freezing again. */}
              <ConfirmButton
                size="sm"
                variant="secondary"
                confirmLabel="اضغط مجدداً لرفع التجميد"
                onConfirm={() => void lift(period.uuid)}
              >
                رفع التجميد
              </ConfirmButton>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
