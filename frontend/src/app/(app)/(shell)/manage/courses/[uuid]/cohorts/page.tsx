"use client";

import { use, useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { NumberField, SelectField, TextField, TextareaField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import {
  AlertIcon,
  ChevronDownIcon,
  ClockIcon,
  HistoryIcon,
  MembersIcon,
  SessionsIcon,
  SettingsIcon,
  UsersIcon,
} from "@/components/icons";
import {
  cohortEventLabel,
  manageCohorts,
  type CohortHistoryEvent,
  type CohortOption,
  type CohortTransferRequest,
} from "@/lib/cohorts";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";

/**
 * The teacher's groups: the runs, who is in each, what happened to them, the
 * queue, and what Q3 is hiding.
 *
 * ⚠️ THE ROSTER AND THE HISTORY HAD ENDPOINTS AND NO SCREEN. `manageCohorts`
 * has carried `members()` and `history()` since 021 and **no file in
 * `frontend/src` called either** — «an endpoint no file calls is a feature
 * nobody has», the family this repository has already paid for three times in a
 * day. A teacher could not see who was in a group, and FR-034's membership log
 * — who joined, who was moved, who was removed and why — existed only in the
 * database.
 *
 * ⚠️ BOTH ARE FETCHED ON EXPAND, ONCE. A course with eight groups would
 * otherwise open with seventeen requests, sixteen of them about panels nobody
 * opened; and the roster is the one read on this page whose cost grows with the
 * number of students.
 *
 * ⚠️ THE HIDDEN-SESSIONS BLOCK IS NOT A CONVENIENCE (FR-025هـ). Creating the
 * first group of a course removes every existing session from every student's
 * timetable at a stroke — a hiding the owner of the timetable does not know
 * about is a silent loss, and this is the only place the product says so.
 *
 * ⚠️ AND IT HAS TWO WORDINGS, BECAUSE UNTIL THE FIRST GROUP EXISTS NOTHING IS
 * HIDDEN. `CohortSessionVisibility`'s second arm shows every unassigned session
 * of a course that has NO groups — that arm IS FR-036 — so on such a course the
 * present tense «محجوبة عن جداول الطلاب» is simply false. It was shipped that
 * way and reported by a teacher looking at seventeen perfectly visible sessions
 * under a warning with no control beneath it.
 *
 * ⚠️ AND THE ASSIGNMENT IS ONE REQUEST FOR THE WHOLE BATCH, never a loop here:
 * forty requests are forty chances for one to fail in the middle, leaving half
 * the timetable hidden with nothing to say which half.
 */

type Member = { uuid: string; name: string; joined_at: string };

/** Which panel of a group card is open. */
type Panel = "members" | "history";

export default function ManageCohortsPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid: courseUuid } = use(params);

  const [groups, setGroups] = useState<CohortOption[]>([]);
  const [queue, setQueue] = useState<CohortTransferRequest[]>([]);
  const [hidden, setHidden] = useState<{
    data: Array<{ uuid: string; title: string; starts_at: string }>;
    meta: { total_hidden: number; assignable: number; already_held: number };
  } | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [open, setOpen] = useState<Record<string, Panel | undefined>>({});
  /** Absent = never fetched; `null` = fetching. */
  const [members, setMembers] = useState<Record<string, Member[] | null>>({});
  const [history, setHistory] = useState<Record<string, CohortHistoryEvent[] | null>>({});

  const [editing, setEditing] = useState<string | null>(null);
  const [edit, setEdit] = useState({ name: "", description: "", capacity: "" });

  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [capacity, setCapacity] = useState("");
  const [assignTo, setAssignTo] = useState("");
  /*
    ⚠️ WHICH SESSIONS, NOT «ALL OF THEM». The batch used to be the whole list
    with no choice in it — so a teacher with two groups sent fourteen dates to
    the first, and the second was left with nothing to be assigned. Measured on a
    real course on 2026-09-09: 14 to «مجموعة اولى», 0 to «مجموعة ثانية», eighteen
    minutes apart.
  */
  const [picked, setPicked] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    manageCohorts
      .list(courseUuid)
      .then((r) => setGroups(r.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));

    manageCohorts
      .transferRequests(courseUuid)
      .then((r) => setQueue(r.data ?? []))
      .catch(() => setQueue([]));

    manageCohorts
      .unassignedSessions(courseUuid)
      .then(setHidden)
      .catch(() => setHidden(null));
  }, [courseUuid]);

  useEffect(load, [load]);

  const run = (promise: Promise<unknown>) => {
    setBusy(true);
    setError(null);

    promise
      .then(() => {
        load();
        // Whatever the panels were showing describes the state before the write
        // — an approved transfer moves a student between two of these rosters.
        setMembers({});
        setHistory({});
      })
      .catch((e: unknown) => setError(userMessage(e)))
      .finally(() => setBusy(false));
  };

  /** Open a panel, fetching it the first time and never again. */
  const toggle = (uuid: string, panel: Panel) => {
    setOpen((current) => ({ ...current, [uuid]: current[uuid] === panel ? undefined : panel }));

    if (panel === "members" && members[uuid] === undefined) {
      setMembers((current) => ({ ...current, [uuid]: null }));
      manageCohorts
        .members(uuid)
        .then((r) => setMembers((current) => ({ ...current, [uuid]: r.data ?? [] })))
        .catch(() => setMembers((current) => ({ ...current, [uuid]: [] })));
    }

    if (panel === "history" && history[uuid] === undefined) {
      setHistory((current) => ({ ...current, [uuid]: null }));
      manageCohorts
        .history(uuid)
        .then((r) => setHistory((current) => ({ ...current, [uuid]: r.data ?? [] })))
        .catch(() => setHistory((current) => ({ ...current, [uuid]: [] })));
    }
  };

  const startEditing = (group: CohortOption) => {
    setEditing(group.uuid);
    setEdit({
      name: group.name,
      description: group.description ?? "",
      capacity: group.capacity === null ? "" : String(group.capacity),
    });
  };

  if (loading) return <RowsSkeleton count={4} />;
  if (failed) return <ErrorState onRetry={load} />;

  const openGroups = groups.filter((group) => group.status !== "archived");
  const students = openGroups.reduce((total, group) => total + group.members_count, 0);
  /*
    Groups with no declared ceiling contribute nothing to the seat count: «no
    limit» is not a number, and counting it as zero would report the most open
    course on the platform as the fullest.
  */
  const seats = openGroups.reduce((total, group) => total + (group.seats_left ?? 0), 0);
  const uncapped = openGroups.some((group) => group.capacity === null);

  return (
    <div className="space-y-6">
      <Link
        href={`/manage/courses/${courseUuid}`}
        className="inline-block rounded text-sm text-ink-muted transition hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        ← الكورس
      </Link>

      <div>
        <h1 className="text-2xl font-bold text-ink">مجموعات الكورس</h1>
        <p className="text-ink-muted">كل مجموعة جولة من الكورس، بموعدها وطلابها وسجلّها.</p>
      </div>

      {/* The three numbers a teacher opens this page to know, before scrolling. */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard icon={<UsersIcon />} label="مجموعات نشطة" value={String(openGroups.length)} />
        <StatCard icon={<MembersIcon />} label="طلاب في المجموعات" value={String(students)} />
        <StatCard
          icon={<SessionsIcon />}
          label="مقاعد متاحة"
          value={uncapped && seats === 0 ? "بلا حدّ" : String(seats)}
          note={uncapped && seats > 0 ? "عدا مجموعات بلا حدّ للسعة" : undefined}
        />
      </div>

      {error !== null && (
        <div className="animate-float-in">
          <Alert tone="danger" title="تعذّر تنفيذ الإجراء">
            {error}
          </Alert>
        </div>
      )}

      {/*
        ⚠️ FIRST ON THE PAGE, ABOVE THE GROUPS THEMSELVES. What is hidden is the
        consequence a teacher did not choose and cannot see anywhere else; a
        block below the fold is a block nobody reads until a student asks why
        their lesson vanished.
      */}
      {hidden !== null && hidden.meta.total_hidden > 0 && (
        <Card>
          <div className="space-y-3">
            {/* The heading follows the same fact as the sentence below it: a
                title in the present tense over a future-tense body is the same
                false claim, one line higher. */}
            <h2 className="flex items-center gap-2 font-bold text-ink">
              {/* ⚠️ `text-accent`, NOT `text-warning-ink`. There is no
                  `warning` token in `@theme` — the warning TONE is
                  `bg-accent/20 text-ink` — and Tailwind v4 emits no rule for a
                  token it has never seen, so the mark would simply not be
                  painted. Fifth time in this family; `theme-tokens.test.ts`
                  knows the name now. */}
              <span className="text-accent">
                <AlertIcon className="h-5 w-5" />
              </span>
              {openGroups.length === 0 ? "حصص بلا مجموعة" : "حصص محجوبة عن الطلاب"}
            </h2>

            <p className="text-sm text-ink-muted">
              {openGroups.length === 0 ? (
                <>
                  <bdi>{hidden.meta.total_hidden}</bdi> حصة في هذا الكورس بلا مجموعة. تظهر لطلابك
                  الآن، وستُحجب عنهم فور إنشاء أوّل مجموعة — أنشئها من النموذج أدناه ثم أسنِدها
                  إليها.
                </>
              ) : (
                <>
                  <bdi>{hidden.meta.total_hidden}</bdi> حصة في هذا الكورس بلا مجموعة، فهي محجوبة عن
                  جداول الطلاب حتى تُسنِدها.
                </>
              )}
              {hidden.meta.already_held > 0 && (
                <>
                  {" "}
                  منها <bdi>{hidden.meta.already_held}</bdi> حصة انعقدت بالفعل ولا يمكن إسنادها —
                  الإسناد بعد الوقوع يصنّف الحصّة ولا يعيد توزيع حقوقها.
                </>
              )}
            </p>

            {hidden.meta.assignable > 0 && openGroups.length > 0 && (
              <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-52">
                  <SelectField
                    id="assign-target"
                    label="أسنِدها إلى"
                    value={assignTo}
                    onChange={setAssignTo}
                    options={[
                      { value: "", label: "اختر مجموعة" },
                      ...openGroups.map((group) => ({ value: group.uuid, label: group.name })),
                    ]}
                  />
                </div>

                <Button
                  disabled={assignTo === "" || picked.length === 0}
                  loading={busy}
                  loadingLabel="جارٍ الإسناد"
                  onClick={() =>
                    run(
                      // ⚠️ ONE REQUEST FOR THE WHOLE SELECTION, never a loop:
                      // forty requests are forty chances for one to fail in the
                      // middle, leaving half the timetable hidden with nothing
                      // to say which half.
                      manageCohorts
                        .assignSessions(courseUuid, assignTo, picked)
                        .then(() => setPicked([])),
                    )
                  }
                >
                  أسنِد <bdi>{picked.length}</bdi> حصة
                </Button>

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() =>
                    setPicked(
                      picked.length === hidden.data.length
                        ? []
                        : hidden.data.map((session) => session.uuid),
                    )
                  }
                >
                  {picked.length === hidden.data.length ? "ألغِ التحديد" : "حدّد الكل"}
                </Button>
              </div>
            )}

            {/* Every assignable one is listed and each is its own choice —
                truncating at ten would hide exactly the dates a teacher is
                trying to split between two groups. */}
            <ul className="space-y-1">
              {hidden.data.map((session) => (
                <li key={session.uuid}>
                  <label className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 text-xs text-ink-muted transition hover:bg-primary-soft">
                    <input
                      type="checkbox"
                      className="size-4 accent-primary"
                      checked={picked.includes(session.uuid)}
                      onChange={() =>
                        setPicked((current) =>
                          current.includes(session.uuid)
                            ? current.filter((uuid) => uuid !== session.uuid)
                            : [...current, session.uuid],
                        )
                      }
                    />
                    <ClockIcon className="h-3.5 w-3.5" />
                    {session.title} — {formatDate(session.starts_at)}
                  </label>
                </li>
              ))}
            </ul>
          </div>
        </Card>
      )}

      <section className="space-y-4">
        <h2 className="font-bold text-ink">المجموعات</h2>

        {groups.length === 0 ? (
          <EmptyState
            title="لا مجموعات بعد"
            description="أنشئ أول مجموعة لتقسيم طلاب هذا الكورس على مواعيد مختلفة."
          />
        ) : (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {groups.map((group, index) => (
              <article
                key={group.uuid}
                /* Staggered. `both` in the keyframe is what stops the delay
                   painting an invisible card, and the reduced-motion block in
                   globals.css zeroes the delay as well as the duration — see
                   the note there. */
                className="animate-float-in rounded-2xl border border-line bg-surface-raised p-5"
                style={{ animationDelay: `${Math.min(index, 6) * 40}ms` }}
              >
                {editing === group.uuid ? (
                  <div className="space-y-3">
                    <TextField
                      id={`edit-name-${group.uuid}`}
                      label="اسم المجموعة"
                      value={edit.name}
                      onChange={(value) => setEdit({ ...edit, name: value })}
                    />
                    <TextareaField
                      id={`edit-description-${group.uuid}`}
                      label="الوصف"
                      value={edit.description}
                      onChange={(value) => setEdit({ ...edit, description: value })}
                      rows={2}
                      hint="سطر يقرؤه الطالب وهو يختار بين المجموعات."
                    />
                    <NumberField
                      id={`edit-capacity-${group.uuid}`}
                      label="السعة"
                      value={edit.capacity}
                      onChange={(value) => setEdit({ ...edit, capacity: value })}
                      min={1}
                      hint="اتركه فارغاً لبلا حدّ."
                    />
                    <div className="flex flex-wrap gap-2">
                      <Button
                        size="sm"
                        loading={busy}
                        loadingLabel="جارٍ الحفظ"
                        disabled={edit.name.trim() === ""}
                        onClick={() => {
                          setEditing(null);
                          run(
                            manageCohorts.update(group.uuid, {
                              name: edit.name.trim(),
                              /* An empty box is «no description», which is a
                                 value and not an omission — sending nothing
                                 would leave the old text standing and there
                                 would be no way to clear it. */
                              description:
                                edit.description.trim() === "" ? null : edit.description.trim(),
                              /* ⚠️ AND AN EMPTY CAPACITY IS `null`, NEVER `0`:
                                 zero is a group nobody may ever join. */
                              capacity: edit.capacity.trim() === "" ? null : Number(edit.capacity),
                            }),
                          );
                        }}
                      >
                        احفظ
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setEditing(null)}>
                        إلغاء
                      </Button>
                    </div>
                  </div>
                ) : (
                  <>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        {/* The group's own page: its week, its students, its
                            history and its settings. Everything below on this
                            card is the summary that gets somebody there. */}
                        <h3 className="font-semibold text-ink">
                          <Link
                            href={`/manage/cohorts/${group.uuid}`}
                            className="rounded transition hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                          >
                            {group.name}
                          </Link>
                        </h3>
                        {group.description !== null && group.description !== "" && (
                          <p className="mt-1 text-sm text-ink-muted">{group.description}</p>
                        )}
                      </div>

                      <div className="flex flex-wrap items-center gap-2">
                        {group.status === "archived" && <Badge tone="neutral">مؤرشفة</Badge>}
                        {group.status === "closed" && <Badge tone="warning">مغلقة للانضمام</Badge>}
                        {group.status === "open" && !group.is_full && (
                          <Badge tone="success">مفتوحة</Badge>
                        )}
                        {group.is_full && <Badge tone="danger">مكتملة</Badge>}
                      </div>
                    </div>

                    <SeatBar
                      members={group.members_count}
                      capacity={group.capacity}
                      seatsLeft={group.seats_left}
                    />

                    {group.schedule_preview.length > 0 ? (
                      <ul className="mt-3 flex flex-wrap gap-2">
                        {group.schedule_preview.map((slot) => (
                          <li
                            key={slot}
                            className="flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1 text-xs text-primary-ink"
                          >
                            <ClockIcon className="h-3.5 w-3.5" />
                            {slot}
                          </li>
                        ))}
                      </ul>
                    ) : (
                      <p className="mt-3 text-xs text-ink-muted">لا مواعيد مجدولة بعد.</p>
                    )}

                    <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3">
                      <PanelToggle
                        open={open[group.uuid] === "members"}
                        onClick={() => toggle(group.uuid, "members")}
                        icon={<MembersIcon className="h-4 w-4" />}
                      >
                        الطلاب (<bdi>{group.members_count}</bdi>)
                      </PanelToggle>

                      <PanelToggle
                        open={open[group.uuid] === "history"}
                        onClick={() => toggle(group.uuid, "history")}
                        icon={<HistoryIcon className="h-4 w-4" />}
                      >
                        السجلّ
                      </PanelToggle>

                      <span className="grow" />

                      {group.status !== "archived" && (
                        <Button
                          variant="ghost"
                          size="sm"
                          iconStart={<SettingsIcon className="h-4 w-4" />}
                          onClick={() => startEditing(group)}
                        >
                          تعديل
                        </Button>
                      )}

                      {group.status === "open" && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => run(manageCohorts.update(group.uuid, { status: "closed" }))}
                        >
                          أغلِق الانضمام
                        </Button>
                      )}

                      {group.status === "closed" && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => run(manageCohorts.update(group.uuid, { status: "open" }))}
                        >
                          افتح الانضمام
                        </Button>
                      )}

                      {/*
                        ⚠️ ARMED, BECAUSE ARCHIVING CANNOT BE TAKEN BACK. It is
                        terminal by design — there is no delete and no
                        un-archive — and it sits a few pixels from «أغلِق
                        الانضمام», which is undone in one tap.
                      */}
                      {group.status !== "archived" && (
                        <ConfirmButton
                          variant="danger"
                          size="sm"
                          confirmLabel="تأكيد الأرشفة"
                          onConfirm={() => run(manageCohorts.archive(group.uuid))}
                        >
                          أرشِف
                        </ConfirmButton>
                      )}
                    </div>

                    {open[group.uuid] === "members" && <RosterPanel rows={members[group.uuid]} />}

                    {open[group.uuid] === "history" && <HistoryPanel rows={history[group.uuid]} />}
                  </>
                )}
              </article>
            ))}
          </div>
        )}

        <Card>
          <h3 className="mb-3 font-semibold text-ink">مجموعة جديدة</h3>

          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            {/*
              ⚠️ THE CONSEQUENCE, BESIDE THE BUTTON THAT CAUSES IT. The first
              group of a course is not a setting — from that moment every session
              still carrying no group leaves each student's discovery list, and
              the curriculum gate (FR-028أ) asks every enrolled student to join a
              group. A teacher who learns that from a student's question learns
              it too late.
            */}
            <TextField
              id="cohort-name"
              label="اسم المجموعة"
              value={name}
              onChange={setName}
              hint={
                groups.length === 0
                  ? "أوّل مجموعة تجعل الكورس كورس مجموعات: تُحجب حصصه غير المُسنَدة حتى تُسنِدها، ويُطلَب من طلابه الانضمام إلى مجموعة."
                  : undefined
              }
            />
            <TextField
              id="cohort-description"
              label="الوصف (اختياري)"
              value={description}
              onChange={setDescription}
              hint="يقرؤه الطالب وهو يختار."
            />
            <NumberField
              id="cohort-capacity"
              label="السعة (اختياري)"
              value={capacity}
              onChange={setCapacity}
              min={1}
              hint="اتركه فارغاً لبلا حدّ"
            />
          </div>

          <div className="mt-4">
            <Button
              disabled={name.trim() === ""}
              loading={busy}
              loadingLabel="جارٍ الإنشاء"
              onClick={() =>
                run(
                  manageCohorts
                    .create(courseUuid, {
                      name: name.trim(),
                      description: description.trim() === "" ? undefined : description.trim(),
                      // ⚠️ AN EMPTY BOX IS `null`, NOT `0`. Zero would be a group
                      // nobody may ever join; null is "no ceiling", which is what
                      // the hint says the empty box means.
                      capacity: capacity.trim() === "" ? null : Number(capacity),
                    })
                    .then(() => {
                      setName("");
                      setDescription("");
                      setCapacity("");
                    }),
                )
              }
            >
              أنشئ مجموعة
            </Button>
          </div>
        </Card>
      </section>

      <Card>
        <div className="space-y-3">
          <h2 className="flex items-center gap-2 font-bold text-ink">
            طلبات الانتقال
            {queue.length > 0 && (
              <span className="rounded-full bg-primary-soft px-2 py-0.5 text-xs text-primary-ink">
                <bdi>{queue.length}</bdi>
              </span>
            )}
          </h2>

          {queue.length === 0 ? (
            <p className="text-sm text-ink-muted">لا طلبات معلَّقة.</p>
          ) : (
            <ul className="space-y-2">
              {queue.map((request) => (
                <li
                  key={request.uuid}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface p-4"
                >
                  <div className="min-w-0">
                    <p className="text-sm text-ink">
                      {request.student?.name ?? "طالب"} — من «{request.from_cohort?.name ?? "—"}» إلى «
                      {request.to_cohort?.name ?? "—"}»
                    </p>
                    {request.student_reason !== null && (
                      <p className="text-xs text-ink-muted">{request.student_reason}</p>
                    )}
                  </div>

                  <div className="flex items-center gap-2">
                    <Button
                      size="sm"
                      loading={busy}
                      loadingLabel="جارٍ"
                      onClick={() => run(manageCohorts.approve(request.uuid))}
                    >
                      وافِق
                    </Button>

                    {/*
                      ⚠️ THE REASON IS PROMPTED FOR, NOT OPTIONAL (FR-028ح). The
                      student reads it; a silent refusal is resubmitted for ever,
                      which is this same queue twice.
                    */}
                    <RejectControl
                      busy={busy}
                      onReject={(reason) => run(manageCohorts.reject(request.uuid, reason))}
                    />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>
    </div>
  );
}

/** One number the page is opened to read. */
function StatCard({
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

/**
 * How full the group is.
 *
 * ⚠️ NO BAR AT ALL WITHOUT A CEILING. `capacity: null` means the group declared
 * none — «unlimited» and «twenty free» are different promises to a student
 * choosing between two groups — and a bar needs a denominator. Drawing one
 * against the member count instead would paint every uncapped group as
 * permanently full, which is the opposite of what it is.
 */
function SeatBar({
  members,
  capacity,
  seatsLeft,
}: {
  members: number;
  capacity: number | null;
  seatsLeft: number | null;
}) {
  if (capacity === null) {
    return (
      <p className="mt-3 text-xs text-ink-muted">
        <bdi>{members}</bdi> طالب · بلا حدّ للسعة
      </p>
    );
  }

  const pct = capacity === 0 ? 0 : Math.min(100, Math.round((members / capacity) * 100));

  return (
    <div className="mt-3">
      <div className="mb-1 flex items-center justify-between text-xs text-ink-muted">
        <span>
          <bdi>{members}</bdi> من <bdi>{capacity}</bdi>
        </span>
        <span>
          المتبقّي <bdi>{seatsLeft ?? 0}</bdi>
        </span>
      </div>
      {/* The width transitions, so a seat taken while the page is open reads as
          a change rather than as a different picture. The numbers above carry
          the meaning; the bar is emphasis, and the label makes it readable to
          anyone who is not looking at it. */}
      <div
        className="h-1.5 overflow-hidden rounded-full bg-primary-soft"
        role="img"
        aria-label={`${members} من ${capacity} مقعداً مشغولة`}
      >
        <div
          className="h-full rounded-full bg-primary transition-[width] duration-500 ease-out"
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  );
}

/** A disclosure whose chevron says which way it is pointing. */
function PanelToggle({
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
      className="flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs text-ink-muted transition hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
    >
      {icon}
      {children}
      <span className={`transition-transform duration-200 ${open ? "rotate-180" : ""}`}>
        <ChevronDownIcon className="h-4 w-4" />
      </span>
    </button>
  );
}

/**
 * Who is in the group right now.
 *
 * ⚠️ NO «REMOVE» BUTTON HERE, DELIBERATELY. `removeMember()` exists on the API
 * and there is no way back from it on this screen: nothing here can put a
 * student INTO a group — `addMember` needs a picker of the course's enrolled
 * students and no endpoint answers that list yet. A control that can only take
 * away is one mis-tap from a student nobody can restore, so the read ships and
 * the write waits for its other half.
 */
function RosterPanel({ rows }: { rows: Member[] | null | undefined }) {
  return (
    <div className="animate-float-in mt-3 rounded-xl bg-surface p-3">
      {rows === null || rows === undefined ? (
        <div className="space-y-2" aria-hidden>
          <div className="h-3 w-40 animate-pulse rounded bg-primary-soft" />
          <div className="h-3 w-28 animate-pulse rounded bg-primary-soft" />
        </div>
      ) : rows.length === 0 ? (
        <p className="text-xs text-ink-muted">لا طلاب في هذه المجموعة بعد.</p>
      ) : (
        <ul className="space-y-1.5">
          {rows.map((row) => (
            <li key={row.uuid} className="flex items-center justify-between gap-3 text-xs">
              <span className="truncate text-ink">{row.name}</span>
              <span className="shrink-0 text-ink-muted">انضمّ {formatDate(row.joined_at)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * What happened to this group's membership (FR-034).
 *
 * ⚠️ THE REASON IS SHOWN WHEN THERE IS ONE. A rejection has to carry one and the
 * student reads it, so a log that dropped it would leave the teacher unable to
 * see what the student was told.
 */
function HistoryPanel({ rows }: { rows: CohortHistoryEvent[] | null | undefined }) {
  return (
    <div className="animate-float-in mt-3 rounded-xl bg-surface p-3">
      {rows === null || rows === undefined ? (
        <div className="space-y-2" aria-hidden>
          <div className="h-3 w-48 animate-pulse rounded bg-primary-soft" />
          <div className="h-3 w-32 animate-pulse rounded bg-primary-soft" />
        </div>
      ) : rows.length === 0 ? (
        <p className="text-xs text-ink-muted">لا حركة على هذه المجموعة بعد.</p>
      ) : (
        <ol className="space-y-2">
          {rows.slice(0, 15).map((row) => (
            <li key={row.uuid} className="border-s-2 border-line ps-3 text-xs">
              <p className="text-ink">
                {row.student?.name ?? "طالب"} — {cohortEventLabel(row.event)}
                {row.event === "transferred" && row.from_cohort?.name != null && (
                  <> من «{row.from_cohort.name}»</>
                )}
              </p>
              {row.reason !== null && <p className="text-ink-muted">{row.reason}</p>}
              <p className="text-ink-muted">{formatDate(row.created_at)}</p>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

/** «ارفض» opens a reason box, because a rejection without one is refused. */
function RejectControl({
  busy,
  onReject,
}: {
  busy: boolean;
  onReject: (reason: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");

  if (!open) {
    return (
      <Button variant="ghost" size="sm" onClick={() => setOpen(true)}>
        ارفض
      </Button>
    );
  }

  return (
    <div className="animate-float-in flex items-end gap-2">
      <div className="w-52">
        <TextField id="reject-reason" label="سبب الرفض" value={reason} onChange={setReason} />
      </div>

      <Button
        variant="danger"
        size="sm"
        disabled={reason.trim() === ""}
        loading={busy}
        loadingLabel="جارٍ"
        onClick={() => {
          onReject(reason.trim());
          setOpen(false);
          setReason("");
        }}
      >
        أرسِل الرفض
      </Button>
    </div>
  );
}
