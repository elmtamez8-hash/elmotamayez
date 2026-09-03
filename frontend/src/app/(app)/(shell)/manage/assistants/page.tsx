"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { AssistantScopeForm } from "@/components/community/AssistantScopeForm";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import { assistants, scopeSummary, type AssistantAssignment } from "@/lib/assistants";
import { userMessage } from "@/lib/errors";
import type { Course } from "@/lib/types";

/**
 * The teacher's team (spec 010 · US1).
 *
 * ⚠️ THERE IS NO «ADD ASSISTANT» BUTTON HERE, and its absence is the design.
 * Membership is written by the invitation flow, which is shipped with its own
 * screen; the assignment rides it. A second door would be a second membership
 * story, and the two disagree the first time somebody uses the older one.
 *
 * ⚠️ AND THE PERMISSIONS ARE NOT EDITED HERE EITHER. What an assistant may DO is
 * a set of ticks on the roles screen this product already has (ق-١) — this page
 * links to it rather than growing a second permission form, because two screens
 * that both claim to answer «what may this person do» is how one of them starts
 * lying.
 */
export default function AssistantsPage() {
  const [rows, setRows] = useState<AssistantAssignment[]>([]);
  const [courses, setCourses] = useState<Course[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [busy, setBusy] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const load = useCallback(() => {
    setState("loading");

    Promise.all([assistants.list(), api.get<{ data: Course[] }>("/courses")])
      .then(([team, courseList]) => {
        setRows(team.data ?? []);
        setCourses(courseList.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  const save = (uuid: string, courseUuids: string[]) => {
    setBusy(uuid);
    setProblem(null);

    assistants
      .setScope(uuid, courseUuids)
      .then(load)
      // Never a raw error: 422 lands under its field, everything else becomes a
      // sentence.
      .catch((error: unknown) => setProblem(userMessage(error)))
      .finally(() => setBusy(null));
  };

  const revoke = (uuid: string) => {
    setBusy(uuid);
    setProblem(null);

    assistants
      .revoke(uuid)
      .then(load)
      .catch((error: unknown) => setProblem(userMessage(error)))
      .finally(() => setBusy(null));
  };

  if (state === "loading") return <RowsSkeleton count={3} />;
  if (state === "error") return <ErrorState onRetry={load} />;

  const active = rows.filter((row) => row.revoked_at === null);
  const past = rows.filter((row) => row.revoked_at !== null);

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">فريق المساعدين</h1>
        <p className="text-sm text-ink-muted">
          من يعمل معك، وعلى أيّ كورسات. أمّا <strong>ما</strong> يستطيع كلٌّ منهم فعله فيُضبط
          بنداً بنداً على دوره من شاشة الأدوار في لوحة الإدارة، لا من هنا. ويُدعى المساعد
          ويُزال من{" "}
          <Link href="/members" className="text-primary-ink underline">
            شاشة الأعضاء
          </Link>
          . ولا يرى أيّ مساعدٍ بياناتٍ ماليّة مهما مُنح من بنود.
        </p>
      </header>

      {problem !== null && <Alert tone="danger" title="تعذّر الحفظ">{problem}</Alert>}

      {active.length === 0 ? (
        <Alert tone="info" title="لا مساعدين بعد">
          يُضاف المساعد بدعوته إلى مساحة عملك من شاشة الأعضاء؛ ويظهر هنا فور قبوله الدعوة.
        </Alert>
      ) : (
        active.map((row) => (
          <Card key={row.uuid}>
            <div className="mb-3 flex items-start justify-between gap-3">
              <div>
                <h2 className="font-medium text-ink">{row.assistant?.name ?? "—"}</h2>
                <p className="text-sm text-ink-muted">{scopeSummary(row)}</p>
              </div>
              <Button
                variant="danger"
                disabled={busy === row.uuid}
                onClick={() => revoke(row.uuid)}
              >
                إنهاء المهمة
              </Button>
            </div>

            <AssistantScopeForm
              assignment={row}
              courses={courses.map((course) => ({ uuid: course.uuid, title: course.title }))}
              onSave={(uuids) => save(row.uuid, uuids)}
              busy={busy === row.uuid}
            />
          </Card>
        ))
      )}

      {past.length > 0 && (
        <Card>
          {/* ⚠️ THE WITHDRAWN ARE LISTED RATHER THAN HIDDEN. «من صحّح هذه الورقة في
              آذار» is a question a teacher asks about somebody who has left, and a
              row that vanishes is a row nobody can ask about (FR-009). */}
          <h2 className="mb-3 font-medium text-ink">مَن انتهت مهمّتهم</h2>
          <ul className="space-y-2">
            {past.map((row) => (
              <li key={row.uuid} className="flex items-center justify-between text-sm text-ink">
                <span>{row.assistant?.name ?? "—"}</span>
                <Badge tone="neutral">أُنهيت المهمة</Badge>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  );
}
