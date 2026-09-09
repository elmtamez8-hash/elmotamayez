"use client";

import { use, useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { NumberField, TextField, TextareaField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import {
  ClockIcon,
  HistoryIcon,
  MembersIcon,
  ScheduleIcon,
  SessionsIcon,
  SettingsIcon,
  SparkIcon,
} from "@/components/icons";
import { classSessions, type ClassSession } from "@/lib/class-sessions";
import {
  cohortEventLabel,
  manageCohorts,
  type CohortHistoryEvent,
  type CohortOption,
} from "@/lib/cohorts";
import { userMessage } from "@/lib/errors";
import { formatDate, formatDateTime, localDateTimeToIso, statusLabel, statusTone } from "@/lib/labels";

/**
 * One group: its week, its students, its history, and its own settings.
 *
 * ⚠️ THE PAGE EXISTS BECAUSE THE TIMETABLE HAD NOWHERE TO BE EDITED FROM. The
 * course's groups screen could create a group and assign a batch of existing
 * sessions to it, and that was all: there was no way to ADD a date to a group,
 * and no way to see the dates it already had beyond three summary labels.
 *
 * ⚠️ AND «FROM MY WEEKLY SCHEDULE» GENERATES SESSIONS, IT DOES NOT REFERENCE
 * SLOTS. `SetAvailability` deletes and recreates every row on each save, so a
 * slot's uuid changes whenever the teacher rewrites their week — a group that
 * pointed at slot uuids would lose its timetable at the first edit. What is
 * stored is a dated session with its own hour, which no later change to the
 * weekly pattern can reach back and move.
 */

export default function ManageCohortPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid: cohortUuid } = use(params);

  const [group, setGroup] = useState<
    (CohortOption & { course: { uuid: string; title: string } | null }) | null
  >(null);
  const [sessions, setSessions] = useState<ClassSession[]>([]);
  const [members, setMembers] = useState<Array<{ uuid: string; name: string; joined_at: string }>>(
    [],
  );
  const [history, setHistory] = useState<CohortHistoryEvent[]>([]);

  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  const [editing, setEditing] = useState(false);
  const [edit, setEdit] = useState({ name: "", description: "", capacity: "" });

  const today = new Date().toISOString().slice(0, 10);
  const [range, setRange] = useState({ from: today, to: today });
  const [oneOff, setOneOff] = useState({ title: "", startsAt: "", duration: "60" });

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    manageCohorts
      .show(cohortUuid)
      .then(setGroup)
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));

    // Bounded from today onwards: a group in its second term has hundreds of
    // past lessons, and the question this page asks is «when do we meet next».
    classSessions
      .list({ cohort: cohortUuid, from: today, order: "asc" })
      .then((r) => setSessions(r.data ?? []))
      .catch(() => setSessions([]));

    manageCohorts
      .members(cohortUuid)
      .then((r) => setMembers(r.data ?? []))
      .catch(() => setMembers([]));

    manageCohorts
      .history(cohortUuid)
      .then((r) => setHistory(r.data ?? []))
      .catch(() => setHistory([]));
  }, [cohortUuid, today]);

  useEffect(load, [load]);

  const run = (promise: Promise<unknown>, done?: () => void) => {
    setBusy(true);
    setError(null);
    setNote(null);

    promise
      .then(() => {
        load();
        done?.();
      })
      .catch((e: unknown) => setError(userMessage(e)))
      .finally(() => setBusy(false));
  };

  if (loading) return <RowsSkeleton count={4} />;
  if (failed || group === null) return <ErrorState onRetry={load} />;

  const courseUuid = group.course?.uuid ?? "";

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2 text-sm text-ink-muted">
        {courseUuid !== "" && (
          <>
            <Link
              href={`/manage/courses/${courseUuid}/cohorts`}
              className="rounded transition hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              ← مجموعات «{group.course?.title}»
            </Link>
          </>
        )}
      </div>

      <div className="animate-float-in flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-ink">{group.name}</h1>
          {group.description !== null && group.description !== "" && (
            <p className="mt-1 text-ink-muted">{group.description}</p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={statusTone(group.status)}>{statusLabel(group.status)}</Badge>
          {group.is_full && <Badge tone="danger">مكتملة</Badge>}
          {group.status !== "archived" && (
            <Button
              size="sm"
              variant="ghost"
              iconStart={<SettingsIcon className="h-4 w-4" />}
              onClick={() => {
                setEditing((was) => !was);
                setEdit({
                  name: group.name,
                  description: group.description ?? "",
                  capacity: group.capacity === null ? "" : String(group.capacity),
                });
              }}
            >
              تعديل
            </Button>
          )}
        </div>
      </div>

      {error !== null && (
        <div className="animate-float-in">
          <Alert tone="danger" title="تعذّر تنفيذ الإجراء">
            {error}
          </Alert>
        </div>
      )}

      {note !== null && (
        <div className="animate-float-in">
          <Alert tone="info" title={note} />
        </div>
      )}

      {editing && (
        <Card>
          <div className="animate-float-in space-y-3">
            <h2 className="font-semibold text-ink">بيانات المجموعة</h2>
            <TextField
              id="group-name"
              label="الاسم"
              value={edit.name}
              onChange={(value) => setEdit({ ...edit, name: value })}
            />
            <TextareaField
              id="group-description"
              label="الوصف"
              rows={2}
              value={edit.description}
              onChange={(value) => setEdit({ ...edit, description: value })}
              hint="سطر يقرؤه الطالب وهو يختار بين المجموعات."
            />
            <NumberField
              id="group-capacity"
              label="السعة"
              min={1}
              value={edit.capacity}
              onChange={(value) => setEdit({ ...edit, capacity: value })}
              hint="اتركه فارغاً لبلا حدّ."
            />
            <div className="flex flex-wrap gap-2">
              <Button
                size="sm"
                loading={busy}
                loadingLabel="جارٍ الحفظ"
                disabled={edit.name.trim() === ""}
                onClick={() =>
                  run(
                    manageCohorts.update(cohortUuid, {
                      name: edit.name.trim(),
                      /* Empty means «no description», a value and not an
                         omission: sending nothing would leave the old text
                         standing with no way to clear it. */
                      description: edit.description.trim() === "" ? null : edit.description.trim(),
                      /* ⚠️ AND AN EMPTY CAPACITY IS `null`, NEVER `0`: zero is a
                         group nobody may ever join. */
                      capacity: edit.capacity.trim() === "" ? null : Number(edit.capacity),
                    }),
                    () => setEditing(false),
                  )
                }
              >
                احفظ
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setEditing(false)}>
                إلغاء
              </Button>

              <span className="grow" />

              {group.status === "open" && (
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => run(manageCohorts.update(cohortUuid, { status: "closed" }))}
                >
                  أغلِق الانضمام
                </Button>
              )}
              {group.status === "closed" && (
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => run(manageCohorts.update(cohortUuid, { status: "open" }))}
                >
                  افتح الانضمام
                </Button>
              )}

              {/* ⚠️ ARMED, BECAUSE ARCHIVING CANNOT BE TAKEN BACK — there is no
                  delete and no un-archive. */}
              <ConfirmButton
                variant="danger"
                size="sm"
                confirmLabel="تأكيد الأرشفة"
                onConfirm={() => run(manageCohorts.archive(cohortUuid))}
              >
                أرشِف
              </ConfirmButton>
            </div>
          </div>
        </Card>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Stat icon={<MembersIcon />} label="الطلاب" value={String(group.members_count)} />
        <Stat
          icon={<SessionsIcon />}
          label="حصص قادمة"
          value={String(sessions.length)}
        />
        <Stat
          icon={<SparkIcon />}
          label="المقاعد"
          value={group.capacity === null ? "بلا حدّ" : `${group.seats_left ?? 0} متاح`}
          note={group.capacity === null ? undefined : `من ${group.capacity}`}
        />
      </div>

      {/*
        ⚠️ ADDING DATES IS TWO CONTROLS, NOT ONE, BECAUSE THEY ANSWER DIFFERENT
        QUESTIONS. «من جدولي الأسبوعيّ» repeats the teacher's weekly pattern over
        a range — the ordinary way a term is filled — and «موعد واحد» is the
        make-up lesson that falls outside it. Folding the second into the first
        would mean editing the weekly schedule to add one Tuesday.
      */}
      <Card>
        <h2 className="mb-1 flex items-center gap-2 font-semibold text-ink">
          <ScheduleIcon className="h-5 w-5" />
          أضِف مواعيد لهذه المجموعة
        </h2>
        <p className="mb-4 text-sm text-ink-muted">
          يولِّد حصصاً من جدول توفّرك الأسبوعيّ داخل المدى. المواعيد المتداخلة أو الواقعة في فترة
          تجميد تُتخطّى ويُقال لك أيّها.
        </p>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <TextField
            id="generate-from"
            label="من تاريخ"
            type="date"
            value={range.from}
            onChange={(from) => setRange({ ...range, from })}
          />
          <TextField
            id="generate-to"
            label="إلى تاريخ"
            type="date"
            value={range.to}
            onChange={(to) => setRange({ ...range, to })}
          />
        </div>

        <div className="mt-4">
          <Button
            loading={busy}
            loadingLabel="جارٍ التوليد"
            disabled={courseUuid === "" || range.from === "" || range.to === "" || range.to <= range.from}
            onClick={() =>
              run(
                classSessions
                  .generate({
                    course_uuid: courseUuid,
                    from: range.from,
                    to: range.to,
                    cohort_uuid: cohortUuid,
                    type: "group",
                    // The group's own ceiling, or a sensible open number when it
                    // declared none — the seat count is the session's, and a
                    // group with no limit is not a session with no seats.
                    seats_total: group.capacity ?? 30,
                    title: group.name,
                  })
                  .then((result) => {
                    setNote(
                      `أُنشِئت ${result.created.length} حصة` +
                        (result.skipped.length > 0
                          ? ` · تُخطّيت ${result.skipped.length} (تداخل أو تجميد)`
                          : ""),
                    );
                  }),
              )
            }
          >
            ولِّد المواعيد
          </Button>
        </div>

        <div className="mt-6 border-t border-line pt-4">
          <h3 className="mb-3 text-sm font-semibold text-ink">أو موعد واحد خارج الجدول</h3>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <TextField
              id="one-off-title"
              label="عنوان الحصة"
              value={oneOff.title}
              onChange={(title) => setOneOff({ ...oneOff, title })}
            />
            <TextField
              id="one-off-starts"
              label="موعد البدء"
              type="datetime-local"
              value={oneOff.startsAt}
              onChange={(startsAt) => setOneOff({ ...oneOff, startsAt })}
            />
            <NumberField
              id="one-off-duration"
              label="المدة (دقيقة)"
              min={5}
              value={oneOff.duration}
              onChange={(duration) => setOneOff({ ...oneOff, duration })}
            />
          </div>
          <div className="mt-4">
            <Button
              variant="secondary"
              loading={busy}
              loadingLabel="جارٍ الإضافة"
              disabled={courseUuid === "" || oneOff.title === "" || oneOff.startsAt === ""}
              onClick={() =>
                run(
                  classSessions.create({
                    course_uuid: courseUuid,
                    title: oneOff.title,
                    type: "group",
                    cohort_uuid: cohortUuid,
                    // ⚠️ CONVERTED, NEVER SENT RAW. The input's value is a naive
                    // wall clock and the API runs on UTC, so the string alone
                    // moves the lesson by the operator's own offset.
                    starts_at: localDateTimeToIso(oneOff.startsAt),
                    duration_minutes: Number(oneOff.duration),
                    seats_total: group.capacity ?? 30,
                  }),
                  () => setOneOff({ title: "", startsAt: "", duration: "60" }),
                )
              }
            >
              أضِف الموعد
            </Button>
          </div>
        </div>
      </Card>

      <Card>
        <h2 className="mb-3 flex items-center gap-2 font-semibold text-ink">
          <ClockIcon className="h-4 w-4" />
          مواعيد المجموعة القادمة
        </h2>

        {sessions.length === 0 ? (
          <EmptyState
            title="لا مواعيد قادمة"
            description="ولِّد مواعيد من جدولك الأسبوعيّ أعلاه، أو أضِف موعداً واحداً."
          />
        ) : (
          <ul className="space-y-2">
            {sessions.map((session, index) => (
              <li
                key={session.uuid}
                className="animate-float-in flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-surface p-3"
                style={{ animationDelay: `${Math.min(index, 8) * 30}ms` }}
              >
                <div className="min-w-0">
                  <p className="truncate text-sm text-ink">{session.title}</p>
                  <p className="text-xs text-ink-muted">{formatDateTime(session.starts_at)}</p>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={statusTone(session.status)}>{statusLabel(session.status)}</Badge>
                  {/* Editing a date is the session's own screen — a second
                      spelling of «change the time» here would be one more place
                      the overlap and freeze rules could disagree. */}
                  <Link
                    href={`/manage/sessions/${session.uuid}`}
                    className="rounded text-xs text-primary-ink underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    تعديل
                  </Link>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <h2 className="mb-3 flex items-center gap-2 font-semibold text-ink">
            <MembersIcon className="h-4 w-4" />
            الطلاب (<bdi>{members.length}</bdi>)
          </h2>

          {members.length === 0 ? (
            <p className="text-sm text-ink-muted">لا طلاب في هذه المجموعة بعد.</p>
          ) : (
            <ul className="space-y-2">
              {members.map((member) => (
                <li
                  key={member.uuid}
                  className="flex items-center justify-between gap-3 text-sm"
                >
                  <span className="truncate text-ink">{member.name}</span>
                  <span className="shrink-0 text-xs text-ink-muted">
                    انضمّ {formatDate(member.joined_at)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card>
          <h2 className="mb-3 flex items-center gap-2 font-semibold text-ink">
            <HistoryIcon className="h-4 w-4" />
            سجلّ العضوية
          </h2>

          {history.length === 0 ? (
            <p className="text-sm text-ink-muted">لا حركة على هذه المجموعة بعد.</p>
          ) : (
            <ol className="space-y-2">
              {history.slice(0, 20).map((row) => (
                <li key={row.uuid} className="border-s-2 border-line ps-3 text-xs">
                  <p className="text-ink">
                    {row.student?.name ?? "طالب"} — {cohortEventLabel(row.event)}
                    {row.event === "transferred" && row.from_cohort?.name != null && (
                      <> من «{row.from_cohort.name}»</>
                    )}
                  </p>
                  {/* ⚠️ THE REASON IS SHOWN WHEN THERE IS ONE. A rejection has to
                      carry one and the student reads it; a log that dropped it
                      would leave the teacher unable to see what the student was
                      told. */}
                  {row.reason !== null && <p className="text-ink-muted">{row.reason}</p>}
                  <p className="text-ink-muted">{formatDate(row.created_at)}</p>
                </li>
              ))}
            </ol>
          )}
        </Card>
      </div>
    </div>
  );
}

/** One number the page is opened to read. */
function Stat({
  icon,
  label,
  value,
  note,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  note?: string;
}) {
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-line bg-surface-raised p-4">
      <span className="rounded-xl bg-primary-soft p-2 text-primary-ink">{icon}</span>
      <div className="min-w-0">
        <p className="text-xs text-ink-muted">{label}</p>
        <p className="text-lg font-bold text-ink">
          <bdi>{value}</bdi>
        </p>
        {note !== undefined && <p className="text-xs text-ink-muted">{note}</p>}
      </div>
    </div>
  );
}
