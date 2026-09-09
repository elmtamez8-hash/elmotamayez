"use client";

import { use, useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { SelectField, TextField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { manageCohorts, type CohortOption, type CohortTransferRequest } from "@/lib/cohorts";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";

/**
 * The teacher's groups: the runs, the queue, and what Q3 is hiding.
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
 * under a warning with no control beneath it (the assign button is gated on
 * there being an open group to assign to, which is exactly what they did not
 * have). The future tense is both true and actionable: it names the consequence
 * of the button directly below.
 *
 * ⚠️ AND THE ASSIGNMENT IS ONE REQUEST FOR THE WHOLE BATCH, never a loop here:
 * forty requests are forty chances for one to fail in the middle, leaving half
 * the timetable hidden with nothing to say which half.
 */

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

  const [name, setName] = useState("");
  const [capacity, setCapacity] = useState("");
  const [assignTo, setAssignTo] = useState("");
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
      .then(load)
      .catch((e: unknown) => setError(userMessage(e)))
      .finally(() => setBusy(false));
  };

  if (loading) return <RowsSkeleton count={4} />;
  if (failed) return <ErrorState onRetry={load} />;

  const openGroups = groups.filter((group) => group.status !== "archived");

  return (
    <div className="space-y-6">
      <Link
        href={`/manage/courses/${courseUuid}`}
        className="inline-block rounded text-sm text-ink-muted hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        ← الكورس
      </Link>

      <h1 className="text-2xl font-bold text-ink">مجموعات الكورس</h1>

      {error !== null && <Alert tone="danger" title="تعذّر تنفيذ الإجراء">{error}</Alert>}

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
            <h2 className="font-bold text-ink">
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
                  disabled={assignTo === ""}
                  loading={busy}
                  loadingLabel="جارٍ الإسناد"
                  onClick={() =>
                    run(
                      manageCohorts.assignSessions(
                        courseUuid,
                        assignTo,
                        hidden.data.map((session) => session.uuid),
                      ),
                    )
                  }
                >
                  أسنِد <bdi>{hidden.meta.assignable}</bdi> حصة
                </Button>
              </div>
            )}

            <ul className="space-y-1 text-xs text-ink-muted">
              {hidden.data.slice(0, 10).map((session) => (
                <li key={session.uuid}>
                  {session.title} — {formatDate(session.starts_at)}
                </li>
              ))}
            </ul>
          </div>
        </Card>
      )}

      <Card>
        <div className="space-y-4">
          <h2 className="font-bold text-ink">المجموعات</h2>

          {groups.length === 0 ? (
            <EmptyState
              title="لا مجموعات بعد"
              description="أنشئ أول مجموعة لتقسيم طلاب هذا الكورس على مواعيد مختلفة."
            />
          ) : (
            <ul className="space-y-2">
              {groups.map((group) => (
                <li
                  key={group.uuid}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface p-4"
                >
                  <div className="min-w-0">
                    <p className="font-semibold text-ink">{group.name}</p>
                    <p className="text-xs text-ink-muted">
                      <bdi>{group.members_count}</bdi> طالب
                      {group.seats_left !== null && (
                        <>
                          {" · "}المتبقّي <bdi>{group.seats_left}</bdi>
                        </>
                      )}
                      {group.schedule_preview.length > 0 && ` · ${group.schedule_preview.join(" · ")}`}
                    </p>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    {group.status === "archived" && <Badge tone="neutral">مؤرشفة</Badge>}
                    {group.status === "closed" && <Badge tone="warning">مغلقة للانضمام</Badge>}
                    {group.is_full && <Badge tone="danger">مكتملة</Badge>}

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
                      terminal by design — there is no delete and no un-archive —
                      and it sits a few pixels from «أغلِق الانضمام», which is
                      undone in one tap.
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
                </li>
              ))}
            </ul>
          )}

          {/*
            ⚠️ THE CONSEQUENCE, BESIDE THE BUTTON THAT CAUSES IT. The first group
            of a course is not a setting — from that moment every session still
            carrying no group leaves each student's discovery list, and the
            curriculum gate (FR-028أ) asks every enrolled student to join a
            group. A teacher who learns that from a student's question learns it
            too late.
          */}
          <div className="flex flex-wrap items-end gap-3 border-t border-line pt-4">
            <div className="min-w-52">
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
            </div>

            <div className="w-40">
              <TextField
                id="cohort-capacity"
                label="السعة (اختياري)"
                value={capacity}
                onChange={setCapacity}
                hint="اتركه فارغاً لبلا حدّ"
              />
            </div>

            <Button
              disabled={name.trim() === ""}
              loading={busy}
              loadingLabel="جارٍ الإنشاء"
              onClick={() =>
                run(
                  manageCohorts
                    .create(courseUuid, {
                      name: name.trim(),
                      // ⚠️ AN EMPTY BOX IS `null`, NOT `0`. Zero would be a group
                      // nobody may ever join; null is "no ceiling", which is what
                      // the hint says the empty box means.
                      capacity: capacity.trim() === "" ? null : Number(capacity),
                    })
                    .then(() => {
                      setName("");
                      setCapacity("");
                    }),
                )
              }
            >
              أنشئ مجموعة
            </Button>
          </div>
        </div>
      </Card>

      <Card>
        <div className="space-y-3">
          <h2 className="font-bold text-ink">طلبات الانتقال</h2>

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
    <div className="flex items-end gap-2">
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
