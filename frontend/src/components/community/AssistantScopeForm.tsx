"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { CheckboxField } from "@/components/ui/Field";
import type { AssistantAssignment, AssistantCourse } from "@/lib/assistants";

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
 * screen interleave into a set neither of them chose.
 */
export function AssistantScopeForm({
  assignment,
  courses,
  onSave,
  busy = false,
}: {
  assignment: AssistantAssignment;
  courses: AssistantCourse[];
  onSave: (courseUuids: string[]) => void;
  busy?: boolean;
}) {
  const [selected, setSelected] = useState<string[]>(
    assignment.is_confined ? assignment.courses.map((course) => course.uuid) : [],
  );

  const toggle = (uuid: string, checked: boolean) =>
    setSelected((current) =>
      checked ? [...current, uuid] : current.filter((value) => value !== uuid),
    );

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        onSave(selected);
      }}
      className="space-y-3"
    >
      {selected.length === 0 && (
        <Alert tone="info" title="بلا تقييد">
          لم تختر أيّ كورس، فيعمل هذا المساعد على <strong>كلّ</strong> كورساتك. اختر كورساً أو
          أكثر لتقصره عليها.
        </Alert>
      )}

      <fieldset className="space-y-2">
        <legend className="mb-1 text-sm font-medium text-ink">كورسات هذا المساعد</legend>
        {courses.map((course) => (
          <CheckboxField
            key={course.uuid}
            id={`scope-${assignment.uuid}-${course.uuid}`}
            label={course.title}
            checked={selected.includes(course.uuid)}
            onChange={(checked) => toggle(course.uuid, checked)}
            disabled={busy}
          />
        ))}
      </fieldset>

      <Button type="submit" disabled={busy}>
        حفظ النطاق
      </Button>
    </form>
  );
}
