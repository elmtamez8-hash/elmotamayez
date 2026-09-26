"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { SelectField, TextField } from "@/components/ui/Field";
import { HistoryIcon, ProgressIcon, UserPlusIcon } from "@/components/icons";
import { StudentProgressPanel } from "@/components/gamification/StudentProgressPanel";
import { StudentCohortHistory } from "./StudentCohortHistory";
import { manageCohorts, type EligibleStudent } from "@/lib/cohorts";
import { errorCode, userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";

type Member = { uuid: string; name: string; joined_at: string };

/** Which detail of a member row is open. */
type Detail = "history" | "progress";

/**
 * The server's refusals of a TEACHER'S add, in the teacher's voice.
 *
 * ⚠️ THE SERVER'S SENTENCES ARE WRITTEN TO THE STUDENT. `CohortRefusal` is
 * shared with the student's own join, so `same_cohort` reads «أنت في هذه
 * المجموعة بالفعل» and `not_enrolled` maps (in `errors.ts`) to a sentence about
 * a LESSON — both addressed to somebody who is not reading this screen.
 * `cohort_full` is phrased about the group and passes through unchanged.
 */
const TEACHER_REFUSALS: Record<string, string> = {
  same_cohort: "هذا الطالب في هذه المجموعة بالفعل.",
  not_enrolled: "هذا الطالب غير مسجَّل في هذا الكورس.",
  already_member: "انضمّ هذا الطالب إلى مجموعة في اللحظة نفسها. حدّث القائمة وأعد المحاولة.",
  cohort_closed: "هذه المجموعة مؤرشفة ولا تقبل طلاباً.",
};

function refusalFor(error: unknown): string {
  const body = (error as { body?: unknown } | null)?.body;

  return TEACHER_REFUSALS[errorCode(body) ?? ""] ?? userMessage(error);
}

/**
 * Who is in a group, with the controls to change it.
 *
 * ⚠️ «إخراج» SHIPS WITH «إضافة طالب», NEVER ALONE. The roster was read-only
 * for a phase because `removeMember()` had no way back on this screen — a
 * control that can only take away is one mis-tap from a student nobody can
 * restore. The picker is the way back, so the two arrive together.
 *
 * ⚠️ «إخراج» IS ARMED (`ConfirmButton`). It sits in a row beside two harmless
 * toggles on a teacher's phone, and it closes a paying student's membership.
 *
 * ⚠️ THE PICKER IS THE SERVER'S LIST, fetched when «إضافة طالب» is pressed —
 * see `manageCohorts.eligibleStudents`. A student already in another group of
 * this course is labelled so, because adding them MOVES them.
 */
export function CohortRoster({
  cohortUuid,
  courseUuid,
  archived,
  refreshKey = 0,
  onChanged,
  rowActions,
}: {
  /** Extra per-row controls a host page adds (the group's page: «راسل»). */
  rowActions?: (member: Member) => React.ReactNode;
  cohortUuid: string;
  courseUuid: string;
  /** The writer refuses an archived group, so no «إضافة طالب» is offered. */
  archived: boolean;
  /** Bumped by the page after a write elsewhere (an approved transfer moves people). */
  refreshKey?: number;
  /** Called after an add or a removal, so the page can redraw its counts. */
  onChanged?: () => void;
}) {
  const [members, setMembers] = useState<Member[] | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const [adding, setAdding] = useState(false);
  const [candidates, setCandidates] = useState<EligibleStudent[] | null>(null);
  const [picked, setPicked] = useState("");
  const [reason, setReason] = useState("");

  const [open, setOpen] = useState<Record<string, Detail | undefined>>({});

  const load = useCallback(() => {
    setLoadFailed(false);

    return manageCohorts
      .members(cohortUuid)
      .then((r) => setMembers(r.data ?? []))
      .catch(() => {
        setMembers([]);
        setLoadFailed(true);
      });
  }, [cohortUuid]);

  useEffect(() => {
    void load();
  }, [load, refreshKey]);

  const openAdd = () => {
    setAdding(true);
    setError(null);
    setNotice(null);
    setCandidates(null);
    setPicked("");

    manageCohorts
      .eligibleStudents(cohortUuid)
      .then((r) => setCandidates(r.data ?? []))
      .catch((e: unknown) => {
        setCandidates([]);
        setError(userMessage(e));
      });
  };

  const add = () => {
    setBusy("add");
    setError(null);
    setNotice(null);

    manageCohorts
      .addMember(cohortUuid, picked, reason.trim())
      .then(() => {
        const name = candidates?.find((c) => c.uuid === picked)?.name ?? "الطالب";
        setNotice(`أُضيف ${name} إلى المجموعة.`);
        setAdding(false);
        setPicked("");
        setReason("");
        void load();
        onChanged?.();
      })
      .catch((e: unknown) => setError(refusalFor(e)))
      .finally(() => setBusy(null));
  };

  const remove = (member: Member) => {
    setBusy(member.uuid);
    setError(null);
    setNotice(null);

    manageCohorts
      .removeMember(cohortUuid, member.uuid)
      .then(() => {
        setNotice(`أُخرِج ${member.name} من المجموعة.`);
        void load();
        onChanged?.();
      })
      .catch((e: unknown) => setError(refusalFor(e)))
      .finally(() => setBusy(null));
  };

  const toggle = (uuid: string, detail: Detail) =>
    setOpen((current) => ({ ...current, [uuid]: current[uuid] === detail ? undefined : detail }));

  return (
    <div className="animate-float-in mt-3 space-y-3 rounded-xl bg-surface p-3">
      {error !== null && <Alert tone="danger" title="تعذّر تنفيذ الإجراء">{error}</Alert>}
      {notice !== null && <Alert tone="success" title={notice} />}
      {loadFailed && <Alert tone="danger" title="تعذّر تحميل طلاب المجموعة. أعد تحميل الصفحة." />}

      {members === null ? (
        <div className="space-y-2" aria-hidden>
          <div className="h-3 w-40 animate-pulse rounded bg-primary-soft" />
          <div className="h-3 w-28 animate-pulse rounded bg-primary-soft" />
        </div>
      ) : members.length === 0 ? (
        !loadFailed && <p className="text-xs text-ink-muted">لا طلاب في هذه المجموعة بعد.</p>
      ) : (
        <ul className="space-y-2">
          {members.map((member) => (
            <li key={member.uuid} className="text-xs">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="min-w-0 truncate text-ink">{member.name}</span>
                <span className="flex flex-wrap items-center gap-1.5">
                  <span className="text-ink-muted">انضمّ {formatDate(member.joined_at)}</span>
                  {/* The history is addressed by course; a group whose course
                      did not resolve has no address to ask. */}
                  {courseUuid !== "" && (
                    <DetailToggle
                      open={open[member.uuid] === "history"}
                      onClick={() => toggle(member.uuid, "history")}
                      icon={<HistoryIcon className="h-3.5 w-3.5" />}
                    >
                      سجل المجموعات
                    </DetailToggle>
                  )}
                  <DetailToggle
                    open={open[member.uuid] === "progress"}
                    onClick={() => toggle(member.uuid, "progress")}
                    icon={<ProgressIcon className="h-3.5 w-3.5" />}
                  >
                    التقدّم
                  </DetailToggle>
                  {rowActions?.(member)}
                  <ConfirmButton
                    variant="ghost"
                    size="sm"
                    confirmLabel={`تأكيد إخراج ${member.name}`}
                    loading={busy === member.uuid}
                    disabled={busy !== null && busy !== member.uuid}
                    onConfirm={() => remove(member)}
                  >
                    إخراج
                  </ConfirmButton>
                </span>
              </div>

              {open[member.uuid] === "history" && (
                <div className="mt-2 rounded-lg border border-line p-2">
                  <StudentCohortHistory courseUuid={courseUuid} studentUuid={member.uuid} />
                </div>
              )}

              {open[member.uuid] === "progress" && (
                <div className="mt-2 rounded-lg border border-line p-2">
                  <StudentProgressPanel studentUuid={member.uuid} />
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {!archived &&
        (adding ? (
          <div className="space-y-2 border-t border-line pt-3">
            {candidates === null ? (
              <div className="h-3 w-40 animate-pulse rounded bg-primary-soft" aria-hidden />
            ) : candidates.length === 0 ? (
              <p className="text-xs text-ink-muted">
                لا طلاب آخرون مسجَّلون في هذا الكورس لإضافتهم.
              </p>
            ) : (
              <>
                <SelectField
                  id={`add-member-${cohortUuid}`}
                  label="الطالب"
                  value={picked}
                  onChange={setPicked}
                  placeholder="اختر طالباً"
                  options={candidates.map((c) => ({
                    value: c.uuid,
                    label:
                      c.current_cohort === null
                        ? c.name
                        : `${c.name} — يُنقَل من «${c.current_cohort.name}»`,
                  }))}
                />
                <TextField
                  id={`add-reason-${cohortUuid}`}
                  label="السبب (اختياري)"
                  value={reason}
                  onChange={setReason}
                  hint="يظهر في سجل الطالب."
                />
              </>
            )}

            <div className="flex flex-wrap gap-2">
              <Button
                size="sm"
                disabled={picked === ""}
                loading={busy === "add"}
                loadingLabel="جارٍ الإضافة"
                onClick={add}
              >
                أضِف
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setAdding(false)}>
                إلغاء
              </Button>
            </div>
          </div>
        ) : (
          <Button
            variant="secondary"
            size="sm"
            iconStart={<UserPlusIcon className="h-4 w-4" />}
            onClick={openAdd}
          >
            إضافة طالب
          </Button>
        ))}
    </div>
  );
}

/** A disclosure for one row's detail — `Button` carries no `aria-expanded`. */
function DetailToggle({
  open,
  onClick,
  icon,
  children,
}: {
  open: boolean;
  onClick: () => void;
  icon: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-expanded={open}
      className="flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-ink-muted transition hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
    >
      {icon}
      {children}
    </button>
  );
}
