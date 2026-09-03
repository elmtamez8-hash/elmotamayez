"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { SelectField } from "@/components/ui/Field";
import { errorMessage } from "@/lib/api";
import { courses, type ExamGate, type LessonDetail, type ReferenceTargets } from "@/lib/courses";

/**
 * Putting one of the course's exams at this position in the tree.
 *
 * Two decisions, and the second is the one that changes what students can do:
 *
 * **Which exam.** Published ones only — the list comes from the server that way.
 * A draft exam placed in a sequential course is a door that never opens.
 *
 * **What it asks.** «يكفي أن يُحاول» means the mark is feedback; «يجب أن ينجح»
 * means nothing after this item opens until the exam's own passing score is
 * reached. The second is spelled out with its consequence attached, because a
 * teacher choosing it is deciding to lock part of their course.
 */
export function ExamPicker({
  courseUuid,
  lesson,
  disabled,
  onSave,
}: {
  courseUuid: string;
  lesson: LessonDetail;
  disabled: boolean;
  onSave: (patch: { reference_uuid?: string; exam_gate?: ExamGate }) => void;
}) {
  const [targets, setTargets] = useState<ReferenceTargets | null>(null);
  const [error, setError] = useState("");
  const [selected, setSelected] = useState(lesson.reference?.uuid ?? "");

  useEffect(() => {
    courses
      .referenceTargets(courseUuid)
      .then(setTargets)
      .catch((err: unknown) => setError(errorMessage(err, "تعذّر تحميل اختبارات هذا الكورس.")));
  }, [courseUuid]);

  const gate: ExamGate = lesson.exam_gate ?? "attempt";

  if (error !== "") {
    return (
      <Alert tone="danger" title="تعذّر تحميل الاختبارات">
        {error}
      </Alert>
    );
  }

  if (targets === null) return <p className="text-sm text-ink-muted">جارٍ تحميل الاختبارات…</p>;

  if (targets.exams.length === 0) {
    return (
      <Alert tone="info" title="لا اختبار منشور في هذا الكورس">
        أنشئ اختباراً وانشره أولاً، ثم ضعه في موضعه من الشجرة. الاختبار المسودّة لا يُفتح
        لطلابك، فوضعه هنا يوقفهم عند باب مغلق.
        <span className="mt-2 block">
          <Button href="/manage/exams" size="sm" variant="secondary">
            اختباراتي
          </Button>
        </span>
      </Alert>
    );
  }

  return (
    <div className="space-y-4">
      <SelectField
        id={`exam-${lesson.uuid}`}
        label="الاختبار"
        hint="اختبارات هذا الكورس المنشورة."
        value={selected}
        disabled={disabled}
        placeholder="— اختر اختباراً —"
        options={targets.exams.map((exam) => ({
          value: exam.uuid,
          label: `${exam.title} — النجاح من ${exam.passing_score}٪ · ${exam.questions_count} سؤالاً`,
        }))}
        onChange={(value) => {
          setSelected(value);
          if (value !== "") onSave({ reference_uuid: value });
        }}
      />

      <SelectField
        id={`gate-${lesson.uuid}`}
        label="ما يطلبه هذا العنصر"
        hint={
          gate === "pass"
            ? "لن يُفتح ما بعد هذا العنصر لطالب لم يجتز الاختبار — في الكورس المتسلسل."
            : "يكفي أن يؤدّي الطالب الاختبار ويسلّم إجابته؛ الدرجة لا تحجبه."
        }
        value={gate}
        disabled={disabled}
        options={[
          { value: "attempt", label: "يكفي أن يُحاول" },
          { value: "pass", label: "يجب أن ينجح" },
        ]}
        onChange={(value) => onSave({ exam_gate: value as ExamGate })}
      />

      {lesson.reference === null && (
        // Said before the teacher hits publish rather than as a refusal
        // afterwards: an exam item with no exam cannot be published at all.
        <Alert tone="warning" title="هذا العنصر لا يشير إلى اختبار بعد">
          اختر اختباراً قبل نشره — العنصر بلا اختبار لا يُنشر.
        </Alert>
      )}
    </div>
  );
}
