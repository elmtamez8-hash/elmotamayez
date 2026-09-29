"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { FilterBar } from "@/components/ui/FilterBar";
import { PageHeader } from "@/components/ui/PageHeader";
import { RecordList, RecordRow } from "@/components/ui/RecordList";
import { SectionHeading } from "@/components/ui/SectionHeading";
import {
  AssignmentIcon,
  ChevronDownIcon,
  ClockIcon,
  DownloadIcon,
  EditIcon,
  GradingIcon,
  ListIcon,
  PublishIcon,
  ScheduleIcon,
  SparkIcon,
  UsersIcon,
} from "@/components/icons";
import { ExtensionForm } from "@/components/assignments/ExtensionForm";
import { NumberField, TextareaField } from "@/components/ui/Field";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { assignments, stateLabel, type Assignment, type Submission } from "@/lib/assignments";
import { counted, formatDateTime, NOUNS } from "@/lib/labels";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { usePagedList } from "@/lib/use-paged-list";
import type { Course } from "@/lib/types";

import { AssignmentForm } from "./AssignmentForm";
import { arabicNumber } from "@/lib/numerals";

type StatusFilter = "all" | "published" | "draft";

/**
 * The teacher's homework: what is set, and what is waiting to be marked.
 *
 * ⚠️ THE DRAFT IS SHOWN AS A DRAFT, and it is the teacher's own list that
 * legitimately contains one. A draft blocks nothing (FR-042) — US7's gate
 * ignores it — so the badge is not decoration: a teacher who thinks unfinished
 * homework is holding their class back will publish it half-written.
 *
 * Laid out on the staff kit (`docs/design/manage-pages.md`): one `RecordRow` per
 * assignment, its marking panel as the row's own children.
 *
 * ⚠️ EVERY NUMBER AND EVERY FILTER IS THE SERVER'S. The list is paginated (30 a
 * page), so the search and the chips are sent as `q` and `status`, the chip
 * counts are `meta.counts` and the summary is `meta.total` — a figure summed or
 * a match run here would be «page one», presented as the whole. «عرض المزيد»
 * reaches the rest.
 */
export default function ManageAssignmentsPage() {
  const [status, setStatus] = useState<StatusFilter>("all");
  const [query, setQuery] = useState("");
  const searched = useDebouncedValue(query);
  const [open, setOpen] = useState<string | null>(null);
  // `"new"`, the assignment being edited, or nothing — one form on the page.
  const [editing, setEditing] = useState<Assignment | "new" | null>(null);
  const [courses, setCourses] = useState<Course[]>([]);
  // A refused publish, against the row that was refused. It used to be
  // `.catch(() => load())`: the server's «واجبٌ بلا موعد لا يُنشر» was thrown
  // away and the teacher watched the button do nothing.
  const [publishError, setPublishError] = useState<{ uuid: string; message: string } | null>(null);

  /*
   * Paged, searched and filtered on the SERVER (30 a page). A confined
   * assistant's list is already narrowed to their own courses there, and so are
   * the counts.
   */
  const fetchPage = useCallback(
    (page: number) =>
      assignments.list({ page, q: searched, status: status === "all" ? undefined : status }),
    [searched, status],
  );
  const list = usePagedList<Assignment, Record<Assignment["status"], number>>(fetchPage);
  const { rows: items, state, total, counts } = list;
  const load = () => void list.reload();

  // The course picker. A failure leaves it empty, which still offers «كل طلابي»
  // — the form stays usable rather than blocked on a list.
  useEffect(() => {
    api
      .get<{ data: Course[] }>("/courses?per_page=200")
      .then((response) => setCourses(response.data ?? []))
      .catch(() => setCourses([]));
  }, []);

  const publish = (uuid: string) => {
    setPublishError(null);

    assignments
      .publish(uuid)
      .then(load)
      .catch((cause: unknown) => setPublishError({ uuid, message: userMessage(cause) }));
  };

  const saved = () => {
    setEditing(null);
    load();
  };

  // Every figure is the server's: `counts` spans the whole search, «الكل» is
  // their sum — never `items.length`, which is only what is loaded so far.
  const drafts = counts?.draft;
  const publishedCount = counts?.published;
  const everything =
    drafts !== undefined && publishedCount !== undefined ? drafts + publishedCount : undefined;
  const filtering = searched.trim() !== "" || status !== "all";
  const clearFilters = () => {
    setQuery("");
    setStatus("all");
  };

  return (
    <div className="space-y-8">
      <PageHeader
        Icon={AssignmentIcon}
        title="الواجبات"
        description="ما نشرته لطلابك، وكم سلّم منهم، وما ينتظر تصحيحك."
        actions={
          editing === null ? (
            <Button iconStart={<SparkIcon />} onClick={() => setEditing("new")}>
              واجب جديد
            </Button>
          ) : undefined
        }
      />

      {editing !== null && (
        <AssignmentForm
          key={editing === "new" ? "new" : editing.uuid}
          editing={editing === "new" ? null : editing}
          courses={courses}
          onSaved={saved}
          onCancel={() => setEditing(null)}
        />
      )}

      <section aria-labelledby="assignment-list" className="space-y-4">
        <SectionHeading
          id="assignment-list"
          Icon={ListIcon}
          title="واجباتك"
          description={
            everything !== undefined && everything > 0
              ? counted(everything, NOUNS.assignments)
              : undefined
          }
        />

        {/*
          Search and chips are the SERVER's (30 a page): a match or a count
          computed here would describe page one and read as the whole list. The
          bar stays mounted once anything has loaded, so a search that empties
          the list can still be cleared.
        */}
        {list.settled && (items.length > 0 || filtering) && (
          <FilterBar
            search={{
              id: "assignment-search",
              label: "ابحث في واجباتك",
              value: query,
              onChange: setQuery,
              placeholder: "عنوان الواجب",
            }}
            filters={{
              label: "حالة الواجب",
              value: status,
              onChange: (key) => setStatus(key as StatusFilter),
              options: [
                { key: "all", label: "الكل", count: everything },
                { key: "published", label: "المنشورة", count: publishedCount },
                { key: "draft", label: "المسوّدات", count: drafts },
              ],
            }}
            summary={
              state === "ready" && total !== null ? counted(total, NOUNS.assignments) : undefined
            }
          />
        )}

        {state === "loading" && <RowsSkeleton count={3} />}

        {state === "error" && <ErrorState onRetry={load} />}

        {state === "ready" && items.length === 0 && !filtering && (
          <EmptyState
            Icon={AssignmentIcon}
            title="لا واجبات بعد"
            description="اضغط «واجب جديد» لتكتب أوّل واجب. يُحفَظ مسوّدةً لا يراها الطلاب حتى تنشره."
          />
        )}

        {state === "ready" && items.length === 0 && filtering && (
          <EmptyState
            title="لا واجب يطابق"
            description="لا واجب بهذا البحث أو بهذه الحالة. امسح البحث لترى واجباتك كلّها."
            action={
              <Button variant="secondary" size="sm" onClick={clearFilters}>
                مسح البحث
              </Button>
            }
          />
        )}

        {state === "ready" && items.length > 0 && (
          <RecordList labelledBy="assignment-list">
            {items.map((assignment) => {
              const isOpen = open === assignment.uuid;
              const regionId = `submissions-${assignment.uuid}`;
              const refusal =
                publishError !== null && publishError.uuid === assignment.uuid
                  ? publishError.message
                  : null;
              const published = assignment.status === "published";

              return (
                <RecordRow
                  key={assignment.uuid}
                  level={4}
                  Icon={AssignmentIcon}
                  tone={published ? "info" : "neutral"}
                  title={assignment.title}
                  status={
                    <Badge tone={published ? "success" : "neutral"}>
                      {published ? "منشور" : "مسوّدة"}
                    </Badge>
                  }
                  /* After «من» the dual is «درجتين», and 3–10 is «درجات» — «من 10 درجة» shipped. */
                  description={`من ${counted(assignment.points, { ...NOUNS.points, two: "درجتين" })}`}
                  meta={[
                    ...(assignment.due_at !== null
                      ? [
                          {
                            key: "due",
                            label: "الموعد",
                            Icon: ScheduleIcon,
                            value: formatDateTime(assignment.due_at),
                          },
                        ]
                      : []),
                    {
                      key: "submitted",
                      label: "سلّم",
                      Icon: UsersIcon,
                      value: arabicNumber(assignment.submitted_count ?? 0),
                    },
                    {
                      key: "pending",
                      label: "ينتظر التصحيح",
                      Icon: ClockIcon,
                      value: arabicNumber(assignment.pending_count ?? 0),
                    },
                  ]}
                  actions={
                    <>
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => setOpen(isOpen ? null : assignment.uuid)}
                        expanded={isOpen}
                        controls={regionId}
                        iconStart={<GradingIcon className="h-4 w-4" />}
                        iconEnd={
                          <span
                            className={`inline-flex transition-transform duration-200 ${isOpen ? "rotate-180" : ""}`}
                          >
                            <ChevronDownIcon className="h-4 w-4" />
                          </span>
                        }
                      >
                        {isOpen ? "أخفِ التسليمات" : "التسليمات"}
                      </Button>
                      {!published && (
                        <Button
                          size="sm"
                          variant="ghost"
                          iconStart={<PublishIcon />}
                          onClick={() => publish(assignment.uuid)}
                        >
                          انشره
                        </Button>
                      )}
                      <Button
                        size="sm"
                        variant="ghost"
                        iconStart={<EditIcon />}
                        onClick={() => setEditing(assignment)}
                      >
                        عدّل
                      </Button>
                    </>
                  }
                >
                  {refusal !== null || isOpen ? (
                    <div className="space-y-3">
                      {refusal !== null && <Alert tone="danger" title={refusal} />}
                      {isOpen && (
                        <div id={regionId} className="banner-rise">
                          <SubmissionList
                            assignmentUuid={assignment.uuid}
                            points={assignment.points}
                          />
                        </div>
                      )}
                    </div>
                  ) : undefined}
                </RecordRow>
              );
            })}
          </RecordList>
        )}

        {list.moreFailed && <Alert tone="danger" title="تعذّر تحميل المزيد. حاول مرّة أخرى." />}

        {state === "ready" && list.hasMore && (
          <div className="flex justify-center">
            <Button
              variant="secondary"
              loading={list.loadingMore}
              loadingLabel="جارٍ التحميل…"
              onClick={() => void list.loadMore()}
            >
              عرض المزيد
            </Button>
          </div>
        )}
      </section>
    </div>
  );
}

function SubmissionList({ assignmentUuid, points }: { assignmentUuid: string; points: number }) {
  const [rows, setRows] = useState<Submission[] | null>(null);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setFailed(false);

    assignments
      .submissions(assignmentUuid)
      .then((response) => setRows(response.data ?? []))
      .catch(() => setFailed(true));
  }, [assignmentUuid]);

  useEffect(load, [load]);

  if (failed) return <ErrorState onRetry={load} />;
  if (rows === null) return <RowsSkeleton count={2} />;

  /*
   | ⚠️ THE EXTENSION FORM SITS ABOVE THE LIST, AND IS THERE WHEN IT IS EMPTY.
   | The student who most needs more time is the one who has handed nothing in
   | — and before the sweep runs they have no row here at all. The grant
   | creates it, which is why the list reloads afterwards.
   */
  return (
    <div className="space-y-4 border-t border-line pt-4">
      <ExtensionForm assignmentUuid={assignmentUuid} onGranted={load} />

      {rows.length === 0 ? (
        <p className="text-sm text-ink-muted">لم يسلّم أحدٌ بعد.</p>
      ) : (
        <ul className="space-y-3" aria-label="التسليمات">
          {rows.map((row) => (
            <li key={row.uuid} className="rounded-2xl bg-surface p-4">
              <SubmissionRow
                assignmentUuid={assignmentUuid}
                row={row}
                points={points}
                onGraded={load}
              />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function badgeTone(state: string | undefined) {
  switch (state) {
    case "late":
      return "warning" as const;
    case "missed":
      return "danger" as const;
    case "pending":
      return "info" as const;
    default:
      return "success" as const;
  }
}

function SubmissionRow({
  assignmentUuid,
  row,
  points,
  onGraded,
}: {
  assignmentUuid: string;
  row: Submission;
  points: number;
  onGraded: () => void;
}) {
  const [score, setScore] = useState(row.score === null ? "" : String(row.score));
  const [feedback, setFeedback] = useState(row.feedback ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [scoreError, setScoreError] = useState("");
  const [opening, setOpening] = useState(false);
  const [fileError, setFileError] = useState("");

  /*
   | ⚠️ THE FILE IS FETCHED, NEVER LINKED. The row used to say only that a file
   | existed (`has_file`) — the teacher could see a worksheet had been handed in
   | and had no way to read it. The route is behind `auth:sanctum`, so a plain
   | link answers 401; `assignments.openFile()` fetches it with the bearer.
   */
  const openFile = async () => {
    setOpening(true);
    setFileError("");

    try {
      await assignments.openFile(assignmentUuid, row.uuid);
    } catch (cause: unknown) {
      setFileError(userMessage(cause));
    } finally {
      setOpening(false);
    }
  };

  const save = async () => {
    /*
     | ⚠️ AN EMPTY BOX IS NOT A ZERO. `Number("")` is 0, so pressing «اعتمد» on a
     | box left blank recorded a zero on the student's work — a mark the teacher
     | never gave, and one that reads to the student and their guardian exactly
     | like a real one.
     */
    if (score.trim() === "" || !Number.isFinite(Number(score))) {
      setScoreError("أدخل الدرجة قبل اعتمادها.");

      return;
    }

    setScoreError("");
    setSaving(true);
    setError("");

    try {
      await assignments.grade(row.uuid, Number(score), feedback);
      onGraded();
    } catch (cause: unknown) {
      setError(userMessage(cause));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex items-start gap-3">
        <Avatar url={null} name={row.student?.name ?? "—"} size="sm" />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="min-w-0 break-words font-bold text-ink">{row.student?.name ?? "—"}</p>
            <Badge tone={badgeTone(row.state)}>
              {stateLabel(row.state)}
            </Badge>
          </div>

          {row.submitted_at != null && (
            <p className="mt-0.5 flex items-center gap-1 text-xs text-ink-muted">
              <ScheduleIcon className="h-4 w-4 shrink-0" />
              سُلّم {formatDateTime(row.submitted_at)}
            </p>
          )}

          {row.extension_until != null && (
            <p className="mt-0.5 flex items-center gap-1 text-xs text-ink-muted">
              <ClockIcon className="h-4 w-4 shrink-0" />
              مُنح مهلةً حتى {formatDateTime(row.extension_until)}
            </p>
          )}
        </div>
      </div>

      {/* A plain inset, never a coloured side border (the contract's don't list). */}
      {row.answer_text !== null && row.answer_text !== "" && (
        <blockquote className="whitespace-pre-wrap rounded-2xl border border-line bg-surface-raised p-3 text-sm leading-relaxed text-ink">
          {row.answer_text}
        </blockquote>
      )}

      {row.has_file && (
        <div>
          <Button
            size="sm"
            variant="secondary"
            iconStart={<DownloadIcon />}
            onClick={openFile}
            loading={opening}
            loadingLabel="جارٍ التنزيل…"
          >
            نزّل الملف المرفق
          </Button>
        </div>
      )}

      {fileError !== "" && <Alert tone="danger" title={fileError} />}

      {error !== "" && <Alert tone="danger" title={error} />}

      {/* ⚠️ `pending` BELONGS ON THIS SIDE OF THE BRANCH TOO. It means the
          student was given longer and has not handed in yet — the server refuses
          to mark it (422), so a grade box here is a form that cannot be
          submitted, offered beside the one student who was told they had time. */}
      {row.state === "missed" || row.state === "pending" ? (
        <p className="text-sm text-ink-muted">
          {row.state === "pending" ? "مُنح مهلةً ولم يسلّم بعد." : "لا شيء سُلّم لتصحيحه."}
        </p>
      ) : (
        <div className="grid gap-3 sm:grid-cols-[8rem_1fr]">
          <NumberField
            id={`score-${row.uuid}`}
            label={`الدرجة (${arabicNumber(points)})`}
            value={score}
            onChange={setScore}
            error={scoreError || undefined}
            min={0}
            max={points}
            step={0.25}
          />
          <TextareaField
            id={`feedback-${row.uuid}`}
            label="ملاحظة"
            rows={2}
            value={feedback}
            onChange={setFeedback}
          />
        </div>
      )}

      {row.state !== "missed" && row.state !== "pending" && (
        <div className="flex items-center gap-3">
          <Button onClick={save} loading={saving} loadingLabel="جارٍ الحفظ…">
            {row.is_graded ? "عدّل الدرجة" : "اعتمد الدرجة"}
          </Button>
          {/* The penalty is stated on the row, not left to be inferred from a
              mark lower than the number the teacher just typed. */}
          {(row.late_penalty_applied_pct ?? 0) > 0 && (
            <p className="text-sm text-ink-muted">
              خُصم <bdi>{arabicNumber(row.late_penalty_applied_pct ?? 0)}</bdi>٪ للتأخير.
            </p>
          )}
        </div>
      )}
    </div>
  );
}
