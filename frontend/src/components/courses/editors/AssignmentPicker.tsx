"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { SelectField } from "@/components/ui/Field";
import { errorMessage } from "@/lib/api";
import { courses, type LessonDetail, type ReferenceTargets } from "@/lib/courses";
import { formatDateTime } from "@/lib/labels";

/**
 * Putting one of the course's homework assignments at this position in the tree.
 *
 * The homework itself is written on `/manage/assignments` — its deadline, its
 * points, how it is handed in. This screen only decides WHERE in the course it
 * sits, which is why there is one select and nothing else.
 *
 * ⚠️ PUBLISHED homework of THIS course only, and the list comes from the server
 * that way (`ReferenceTargetController`). A draft placed in the tree is a door
 * that opens onto nothing, and the server refuses the save anyway — so offering
 * it here would be a choice that fails.
 *
 * And the item completes itself when the student hands the work in — said here
 * so the teacher does not go looking for a «mark done» control on the student's
 * side that deliberately does not exist.
 */
export function AssignmentPicker({
  courseUuid,
  lesson,
  disabled,
  onSave,
}: {
  courseUuid: string;
  lesson: LessonDetail;
  disabled: boolean;
  onSave: (patch: { reference_uuid: string }) => void;
}) {
  const [targets, setTargets] = useState<ReferenceTargets | null>(null);
  const [error, setError] = useState("");
  const [selected, setSelected] = useState(lesson.reference?.uuid ?? "");

  useEffect(() => {
    courses
      .referenceTargets(courseUuid)
      .then(setTargets)
      .catch((err: unknown) => setError(errorMessage(err, "تعذّر تحميل واجبات هذا الكورس.")));
  }, [courseUuid]);

  if (error !== "") {
    return (
      <Alert tone="danger" title="تعذّر تحميل الواجبات">
        {error}
      </Alert>
    );
  }

  if (targets === null) return <p className="text-sm text-ink-muted">جارٍ تحميل الواجبات…</p>;

  if (targets.assignments.length === 0) {
    return (
      <Alert tone="info" title="لا واجب منشور في هذا الكورس">
        أنشئ واجباً لهذا الكورس وانشره أولاً، ثم ضعه في موضعه من الشجرة. الواجب المسودّة لا
        يُسلَّم، فوضعه هنا يوقف طلابك عند باب مغلق.
        <span className="mt-2 block">
          <Button href="/manage/assignments" size="sm" variant="secondary">
            واجباتي
          </Button>
        </span>
      </Alert>
    );
  }

  return (
    <div className="space-y-4">
      <SelectField
        id={`assignment-${lesson.uuid}`}
        label="الواجب"
        hint="واجبات هذا الكورس المنشورة. يكتمل العنصر عند الطالب حين يسلّم الواجب."
        value={selected}
        disabled={disabled}
        placeholder="— اختر واجباً —"
        options={targets.assignments.map((assignment) => ({
          value: assignment.uuid,
          label:
            assignment.due_at !== null
              ? `${assignment.title} — يُسلَّم قبل ${formatDateTime(assignment.due_at)}`
              : assignment.title,
        }))}
        onChange={(value) => {
          setSelected(value);
          if (value !== "") onSave({ reference_uuid: value });
        }}
      />

      {lesson.reference === null && (
        // Said before the teacher presses publish rather than as a refusal
        // afterwards: an assignment item with no homework cannot be published.
        <Alert tone="warning" title="هذا العنصر لا يشير إلى واجب بعد">
          اختر واجباً قبل نشره — العنصر بلا واجب لا يُنشر.
        </Alert>
      )}
    </div>
  );
}
