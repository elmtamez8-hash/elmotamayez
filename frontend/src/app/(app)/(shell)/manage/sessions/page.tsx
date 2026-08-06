"use client";

import { useCallback, useEffect, useState } from "react";

import { SessionCard } from "@/components/sessions/SessionCard";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, TextField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { classSessions, type ClassSession, type GenerateResult } from "@/lib/class-sessions";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";

/**
 * The teacher's calendar, and the generator that fills it.
 *
 * The weekly availability this reads has been published on the public
 * marketplace since spec 001 with no way to book into it. This screen is the
 * producer that was missing.
 */
export default function ManageSessionsPage() {
  const [sessions, setSessions] = useState<ClassSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [teacherProfileId, setTeacherProfileId] = useState("");
  const [generating, setGenerating] = useState(false);
  const [result, setResult] = useState<GenerateResult | null>(null);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});

  // The one-off form. Shares `teacherProfileId` with the generator above: both
  // schedule for the same person, and asking twice on one screen is a question
  // with two answers that can disagree.
  const [oneOff, setOneOff] = useState({ title: "", startsAt: "", duration: "60", seats: "1" });
  const [creating, setCreating] = useState(false);
  const [oneOffError, setOneOffError] = useState("");
  const [oneOffErrors, setOneOffErrors] = useState<Record<string, string>>({});

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    classSessions
      .list()
      .then((response) => setSessions(response.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const generate = async () => {
    setGenerating(true);
    setError("");
    setErrors({});
    setResult(null);

    try {
      setResult(
        await classSessions.generate({ teacher_profile_id: teacherProfileId, from, to }),
      );
      load();
    } catch (err: unknown) {
      setErrors(fieldErrors(err));
      setError(userMessage(err));
    } finally {
      setGenerating(false);
    }
  };

  const createOne = async () => {
    setCreating(true);
    setOneOffError("");
    setOneOffErrors({});

    const seats = Number(oneOff.seats);

    try {
      await classSessions.create({
        teacher_profile_id: teacherProfileId,
        title: oneOff.title,
        // Declared, never inferred from the seat count (FR-001أ) — but one seat
        // has exactly one meaning, and making the teacher say it twice invites
        // the pair to disagree.
        type: seats === 1 ? "individual" : "group",
        starts_at: oneOff.startsAt,
        duration_minutes: Number(oneOff.duration),
        seats_total: seats,
      });

      setOneOff({ title: "", startsAt: "", duration: "60", seats: "1" });
      load();
    } catch (err: unknown) {
      setOneOffErrors(fieldErrors(err));
      setOneOffError(userMessage(err));
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold text-ink">حصصي</h2>

      <Card>
        <h3 className="mb-4 font-semibold text-ink">توليد حصص من جدول التوفّر</h3>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <TextField
            id="teacher_profile_id"
            label="مُعرّف ملف المدرّس"
            value={teacherProfileId}
            onChange={setTeacherProfileId}
            error={errors.teacher_profile_id}
          />
          <TextField
            id="from"
            label="من تاريخ"
            type="date"
            value={from}
            onChange={setFrom}
            error={errors.from}
          />
          <TextField
            id="to"
            label="إلى تاريخ"
            type="date"
            value={to}
            onChange={setTo}
            error={errors.to}
          />
        </div>

        <div className="mt-4 flex items-center gap-3">
          <Button
            onClick={generate}
            loading={generating}
            disabled={from === "" || to === "" || teacherProfileId === ""}
          >
            توليد
          </Button>
          {/* Reached from here rather than from the nav: freezing is an action
              on the calendar, not a section of the product. */}
          <Button href="/manage/freeze" variant="secondary">
            فترات التجميد
          </Button>
        </div>

        {error !== "" && (
          <div className="mt-4">
            <Alert tone="danger" title="تعذّر التوليد">
              {error}
            </Alert>
          </div>
        )}

        {result !== null && (
          <div className="mt-4 space-y-3">
            <Alert tone="success" title="تمّ التوليد">
              أُنشئت <bdi>{result.created.length}</bdi> حصة.
            </Alert>

            {/* Never swallowed: a teacher who is not told what was skipped
                believes their week is full when half of it was never created. */}
            {result.skipped.length > 0 && (
              <Alert tone="warning" title="مواعيد تُخطّيت">
                <ul className="space-y-1">
                  {result.skipped.map((item) => (
                    <li key={item.starts_at}>
                      <bdi>{item.starts_at}</bdi> — {item.reason}
                    </li>
                  ))}
                </ul>
              </Alert>
            )}
          </div>
        )}
      </Card>

      <Card>
        <h3 className="mb-2 font-semibold text-ink">حصة واحدة</h3>
        <p className="mb-4 text-sm text-ink-muted">
          خارج الجدول الأسبوعي — موعد بعينه لمرة واحدة (FR-002). التداخل مع حصة أخرى مرفوض،
          وكذلك أي موعد داخل فترة تجميد.
        </p>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <TextField
            id="one_off_title"
            label="عنوان الحصة"
            value={oneOff.title}
            onChange={(title) => setOneOff({ ...oneOff, title })}
            error={oneOffErrors.title}
          />
          <TextField
            id="one_off_starts_at"
            label="موعد البدء"
            type="datetime-local"
            value={oneOff.startsAt}
            onChange={(startsAt) => setOneOff({ ...oneOff, startsAt })}
            error={oneOffErrors.starts_at}
          />
          <NumberField
            id="one_off_duration"
            label="مدة الحصة (دقيقة)"
            value={oneOff.duration}
            onChange={(duration) => setOneOff({ ...oneOff, duration })}
            error={oneOffErrors.duration_minutes}
          />
          <NumberField
            id="one_off_seats"
            label="عدد المقاعد"
            value={oneOff.seats}
            onChange={(seats) => setOneOff({ ...oneOff, seats })}
            error={oneOffErrors.seats_total}
          />
        </div>

        <div className="mt-4">
          <Button
            onClick={createOne}
            loading={creating}
            disabled={
              teacherProfileId === "" || oneOff.title === "" || oneOff.startsAt === ""
            }
          >
            إنشاء الحصة
          </Button>
        </div>

        {oneOffError !== "" && (
          <div className="mt-4">
            <Alert tone="danger" title="تعذّر الإنشاء">
              {oneOffError}
            </Alert>
          </div>
        )}
      </Card>

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : sessions.length === 0 ? (
        <EmptyState
          title="لا حصص بعد"
          description="ولّد حصصاً من جدول توفّرك الأسبوعي لتظهر هنا."
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {sessions.map((session) => (
            <SessionCard
              key={session.uuid}
              session={session}
              href={`/manage/sessions/${session.uuid}`}
            />
          ))}
        </div>
      )}
    </div>
  );
}
