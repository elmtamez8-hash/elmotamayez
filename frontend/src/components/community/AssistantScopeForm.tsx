"use client";

import { useId, useMemo, useState } from "react";

import { CourseThumb } from "@/components/community/AssistantCourse";
import { SearchIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { StatusBadge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import type { AssistantAssignment, AssistantCourse } from "@/lib/assistants";
import { counted, NOUNS } from "@/lib/labels";

/** Below this the whole list fits at a glance and a search box is clutter. */
const SEARCH_FROM = 7;

/**
 * Which courses this assistant works on (spec 010 · FR-004).
 *
 * ⚠️ «بلا تقييد» IS WRITTEN OUT, NOT INFERRED FROM AN EMPTY LIST. No rows means
 * EVERY course on the server, and a screen that showed nothing ticked and said
 * nothing else would read as «this person has been given no courses» — the exact
 * opposite. A teacher who believed that would tick one course meaning to widen
 * the assistant's ground and would in fact narrow it from everything to one.
 *
 * ⚠️ AND THE WHOLE SET IS SUBMITTED. There is no add/remove pair: «which courses
 * is this person on» has to be the answer to one request, or two owners on one
 * screen interleave into a set neither of them chose. The search only HIDES rows:
 * a ticked course filtered out of view is still in the set that is saved.
 *
 * ⚠️ THE SEARCH BOX SITS OUTSIDE THE `<form>`. Inside it, Enter in the box is an
 * implicit submit — a teacher typing a course name and pressing Enter to «find»
 * it would save a half-made scope.
 *
 * ⚠️ AND EACH CHECKBOX IS NAMED BY THE TITLE ALONE (`aria-labelledby`), with the
 * status and teacher as its DESCRIPTION. Folded into the label, a screen reader
 * would read «الرياضيات منشور أحمد» as the course's name.
 */
export function AssistantScopeForm({
  assignment,
  courses,
  onSave,
  busy = false,
  showTeacher = false,
}: {
  assignment: AssistantAssignment;
  courses: AssistantCourse[];
  onSave: (courseUuids: string[]) => void;
  busy?: boolean;
  /** An academy: name each course's teacher, since the reader is not all of them. */
  showTeacher?: boolean;
}) {
  const baseId = useId();
  const [selected, setSelected] = useState<string[]>(
    assignment.is_confined ? assignment.courses.map((course) => course.uuid) : [],
  );
  const [query, setQuery] = useState("");

  const toggle = (uuid: string, checked: boolean) =>
    setSelected((current) =>
      checked ? [...current, uuid] : current.filter((value) => value !== uuid),
    );

  const needle = query.trim();
  const visible = useMemo(() => {
    const folded = needle.toLocaleLowerCase("ar");

    if (folded === "") return courses;

    return courses.filter((course) =>
      [course.title, course.teacher?.name ?? ""].some((text) =>
        text.toLocaleLowerCase("ar").includes(folded),
      ),
    );
  }, [courses, needle]);

  const searchId = `${baseId}-search`;

  const confinedToDeletedOnly = assignment.is_confined && assignment.courses.length === 0;
  // Saving nothing over a live confinement lifts it to EVERY course — the one
  // save on this form that widens access, so it asks twice rather than once.
  const widens = assignment.is_confined && selected.length === 0;

  return (
    <div className="space-y-3">
      {/* ⚠️ «بلا تقييد» is what an EMPTY SET MEANS, which is not always what the
          assistant HAS. Confined only to courses since deleted, nothing is ticked
          while the assistant reaches nothing at all — saying «every course» there
          invited the one save that widens them to the whole workspace. */}
      {selected.length === 0 && confinedToDeletedOnly ? (
        <Alert tone="warning" title="مقصور على كورسات حُذفت">
          لا يصل هذا المساعد الآن إلى أيّ كورس. اختر كورساً أو أكثر لتقصره عليها، أو احفظ بلا
          كورس لترفع التقييد فيعمل على <strong>كلّ</strong> كورساتك.
        </Alert>
      ) : (
        selected.length === 0 && (
          <Alert tone="info" title="بلا تقييد">
            لم تختر أيّ كورس، فيعمل هذا المساعد على <strong>كلّ</strong> كورساتك. اختر كورساً أو
            أكثر لتقصره عليها.
          </Alert>
        )
      )}

      {courses.length >= SEARCH_FROM && (
        <div className="relative">
          <label htmlFor={searchId} className="sr-only">
            ابحث في الكورسات
          </label>
          <span className="pointer-events-none absolute inset-y-0 start-3 grid place-items-center text-ink-muted">
            <SearchIcon />
          </span>
          <input
            id={searchId}
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="ابحث باسم الكورس أو المدرّس"
            className="w-full rounded-full border border-line bg-surface-raised py-2.5 ps-9 pe-4 text-sm text-ink placeholder:text-ink-muted transition-colors hover:border-primary/40 focus-visible:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          />
        </div>
      )}

      <form
        onSubmit={(event) => {
          event.preventDefault();
          // The widening save goes through the two-press control only; an
          // implicit submit must never reach it in one step.
          if (widens) return;
          onSave(selected);
        }}
        className="space-y-3"
      >
        <fieldset className="space-y-2">
          <legend className="mb-2 flex w-full flex-wrap items-baseline justify-between gap-2 text-sm font-medium text-ink">
            <span>كورسات هذا المساعد</span>
            {selected.length > 0 && (
              <span className="text-xs font-normal text-ink-muted" aria-live="polite">
                مختار: {counted(selected.length, NOUNS.courses)}
              </span>
            )}
          </legend>

          {courses.length === 0 ? (
            <p className="rounded-2xl border border-dashed border-line px-4 py-6 text-center text-sm text-ink-muted">
              لا كورسات لديك بعد، فيعمل المساعد على كلّ ما تنشئه.
            </p>
          ) : visible.length === 0 ? (
            <p className="rounded-2xl border border-dashed border-line px-4 py-6 text-center text-sm text-ink-muted" role="status">
              لا كورس يطابق «{needle}».
            </p>
          ) : (
            <ul className="grid gap-2 sm:grid-cols-2">
              {visible.map((course) => {
                const id = `scope-${assignment.uuid}-${course.uuid}`;
                const checked = selected.includes(course.uuid);
                const hasDetails =
                  course.status !== undefined || (showTeacher && Boolean(course.teacher));

                return (
                  <li key={course.uuid}>
                    <label
                      htmlFor={id}
                      className={`flex h-full cursor-pointer items-center gap-3 rounded-2xl border p-2.5 transition-colors duration-150 has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary ${
                        checked
                          ? "border-primary/50 bg-primary-soft/40"
                          : "border-line bg-surface-raised hover:border-primary/40 hover:bg-primary-soft/20"
                      } ${busy ? "cursor-not-allowed opacity-60" : ""}`}
                    >
                      <input
                        id={id}
                        name={id}
                        type="checkbox"
                        checked={checked}
                        onChange={(event) => toggle(course.uuid, event.target.checked)}
                        disabled={busy}
                        aria-labelledby={`${id}-title`}
                        aria-describedby={hasDetails ? `${id}-details` : undefined}
                        className="h-4 w-4 shrink-0 rounded border-line accent-primary focus-visible:outline-none"
                      />
                      <CourseThumb course={course} />
                      <span className="min-w-0 flex-1">
                        <span id={`${id}-title`} className="block truncate text-sm font-medium text-ink">
                          {course.title}
                        </span>
                        {hasDetails && (
                          <span
                            id={`${id}-details`}
                            className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-muted"
                          >
                            {course.status !== undefined && <StatusBadge status={course.status} />}
                            {showTeacher && course.teacher && <span>{course.teacher.name}</span>}
                          </span>
                        )}
                      </span>
                    </label>
                  </li>
                );
              })}
            </ul>
          )}
        </fieldset>

        {widens ? (
          <ConfirmButton
            variant="secondary"
            disabled={busy}
            confirmLabel="اضغط مجدداً ليعمل على كلّ الكورسات"
            onConfirm={() => onSave([])}
          >
            رفع التقييد
          </ConfirmButton>
        ) : (
          <Button type="submit" disabled={busy}>
            حفظ النطاق
          </Button>
        )}
      </form>
    </div>
  );
}
