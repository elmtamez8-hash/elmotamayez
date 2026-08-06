"use client";

import { useCallback, useEffect, useState } from "react";

import { SessionCard } from "@/components/sessions/SessionCard";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { TextField } from "@/components/ui/Field";
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
          {/* The freeze-periods link belongs here and lands with the screen it
              opens (US6). A button pointing at a route that does not exist yet
              is the orphan problem inverted — a promise instead of a page. */}
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
