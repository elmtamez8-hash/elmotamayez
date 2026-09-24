"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { FacetBar, type Facet } from "@/components/filters/FacetBar";
import { AssignmentIcon } from "@/components/icons";
import { AssignmentCard } from "@/components/assignments/AssignmentCard";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { assignments, type Assignment, type AssignmentFilterOptions } from "@/lib/assignments";

/**
 * The student's homework.
 *
 * ⚠️ GROUPED BY WHAT IS STILL OWED, THE WAY «جدولي» GROUPS BY DAY. It was a flat
 * list ordered by deadline, so «إيه المطلوب مني؟» — the only question anybody
 * opens this page with — meant reading the badge on every card and holding the
 * answer in your head. The grouping IS that answer, and the heading is why the
 * cards under it need no second explanation.
 *
 * ⚠️ AND IT SHOWED PAGE ONE AND NOTHING ELSE. `list()` took a page number the
 * page never varied, so a student with a term's homework silently could not
 * reach the older half — no control, no count, no sign that there was more.
 *
 * ⚠️ THE LATE POLICY IS SHOWN BEFORE THE DEADLINE PASSES, not explained after a
 * mark comes back lower than expected. A stated policy exists so it can be read
 * in advance; a penalty a student first meets in their grade is a penalty they
 * reply to their teacher about.
 */

/** The three questions a homework list answers, in the order they are asked. */
const GROUPS: { key: "open" | "awaiting" | "done"; title: string; hint: string }[] = [
  { key: "open", title: "مطلوبٌ منك", hint: "لم يُسلَّم بعد" },
  { key: "awaiting", title: "سُلِّم وينتظر التصحيح", hint: "عند مدرّسك الآن" },
  { key: "done", title: "مُصحَّح", hint: "بدرجته" },
];

function groupOf(assignment: Assignment): "open" | "awaiting" | "done" {
  const mine = assignment.my_submission;

  if (mine?.is_graded === true) return "done";
  if (mine?.submitted_at != null) return "awaiting";

  return "open";
}

export default function AssignmentsPage() {
  const [items, setItems] = useState<Assignment[]>([]);
  const [options, setOptions] = useState<AssignmentFilterOptions | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");

  const [teacher, setTeacher] = useState("");
  const [subject, setSubject] = useState("");
  const [course, setCourse] = useState("");
  const [cohort, setCohort] = useState("");
  const [status, setStatus] = useState("");

  const load = useCallback(
    (target = 1) => {
      setState("loading");

      assignments
        .list({ page: target, teacher, subject, course, cohort })
        .then((response) => {
          const data = response.data ?? [];

          // Appended when paging forward, replaced when the filters changed —
          // otherwise «عرض المزيد» would drop everything above it.
          setItems((current) => (target === 1 ? data : [...current, ...data]));
          setPage(response.meta?.current_page ?? target);
          setLastPage(response.meta?.last_page ?? 1);
          setState("ready");
        })
        .catch(() => setState("error"));
    },
    [teacher, subject, course, cohort],
  );

  useEffect(() => load(1), [load]);

  useEffect(() => {
    assignments
      .filters()
      .then((response) => setOptions(response.data))
      .catch(() => setOptions(null));
  }, []);

  const facets = useMemo<Facet[]>(
    () => [
      { key: "teacher", label: "المدرّس", placeholder: "كلّ المدرّسين", options: options?.teachers ?? [] },
      /*
        ⚠️ THE SUBJECT AND THE COURSE ARE TWO AXES. «الرياضيات» may be taught by
        three teachers as three courses; a student revising the subject wants all
        three, and one preparing for one course's homework wants only it.
      */
      { key: "subject", label: "المادّة", placeholder: "كلّ المواد", options: options?.subjects ?? [] },
      { key: "course", label: "الكورس", placeholder: "كلّ الكورسات", options: options?.courses ?? [] },
      /*
        ⚠️ THE GROUP SELECTS WHAT ITS COURSE WOULD — one open membership per
        course — so it is offered as the name a student actually uses for their
        own timetable rather than as a new cut. `FacetBar` drops it when there is
        only one, which is the usual case and exactly right.
      */
      { key: "cohort", label: "المجموعة", placeholder: "كلّ المجموعات", options: options?.cohorts ?? [] },
      /*
        ⚠️ THE STATE FACET IS BUILT HERE AND NOT ON THE SERVER, deliberately: a
        submission state is not a column — it is the reader's own row read against
        the deadline — so a server-side facet would be a second computation of
        something every card already carries. It is a fixed list rather than a
        derived one for the same reason the difficulty list is: three states that
        cannot change.
      */
      {
        key: "status",
        label: "الحالة",
        placeholder: "كلّ الحالات",
        options: GROUPS.map((group) => ({ uuid: group.key, label: group.title })),
      },
    ],
    [options],
  );

  const setFacet = (key: string, value: string) => {
    if (key === "teacher") setTeacher(value);
    if (key === "subject") setSubject(value);
    if (key === "course") setCourse(value);
    if (key === "cohort") setCohort(value);
    if (key === "status") setStatus(value);
  };

  const groups = useMemo(
    () =>
      GROUPS.filter((group) => status === "" || status === group.key)
        .map((group) => ({ ...group, rows: items.filter((item) => groupOf(item) === group.key) }))
        .filter((group) => group.rows.length > 0),
    [items, status],
  );

  return (
    <div className="space-y-6">
      <PageHeader Icon={AssignmentIcon} title="واجباتي" />

      <FacetBar
        facets={facets}
        values={{ teacher, subject, course, cohort, status }}
        onChange={setFacet}
        onReset={() => {
          setTeacher("");
          setSubject("");
          setCourse("");
          setCohort("");
          setStatus("");
        }}
      />

      {state === "error" ? (
        <ErrorState onRetry={() => load(1)} />
      ) : state === "loading" && items.length === 0 ? (
        <RowsSkeleton count={3} />
      ) : groups.length === 0 ? (
        /*
          Two different silences, said differently. «لا واجبات» is news; «لا
          نتائج للتصفية» is a control the reader can undo, and telling them the
          first when the second is true reads as data that vanished.
        */
        items.length === 0 ? (
          <EmptyState
            title="لا واجبات عليك الآن"
            description="يظهر هنا كلّ واجبٍ ينشره مدرّسك، بموعده وبما سلّمته فيه."
          />
        ) : (
          <EmptyState
            title="لا واجب يطابق التصفية"
            description="وسّع الاختيار أو امسح التصفية لترى الباقي."
          />
        )
      ) : (
        <div className="space-y-6">
          {groups.map((group) => (
            <section key={group.key} className="space-y-3">
              {/* The schedule page's day heading, with the same rule to the end
                  of the row so the eye can find where one group stops. */}
              <h3 className="flex items-center gap-3 text-sm font-semibold text-ink-muted">
                <span>{group.title}</span>
                <span aria-hidden className="h-px flex-1 bg-line" />
                <span className="text-xs font-normal">
                  <bdi>{group.rows.length}</bdi> واجب
                </span>
              </h3>

              <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {group.rows.map((assignment) => (
                  <AssignmentCard
                    key={assignment.uuid}
                    assignment={assignment}
                    /* The group's name comes from the facet payload, which
                       carries its course — so the card prints «مجموعة السبت ٤م»
                       without a lookup per row. */
                    cohortName={
                      options?.cohorts.find((row) => row.course_uuid === assignment.course?.uuid)
                        ?.label ?? null
                    }
                    onSubmitted={() => load(1)}
                  />
                ))}
              </div>
            </section>
          ))}

          {page < lastPage && (
            <div className="flex justify-center">
              <Button
                variant="ghost"
                loading={state === "loading"}
                loadingLabel="جارٍ التحميل…"
                onClick={() => load(page + 1)}
              >
                عرض المزيد
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
