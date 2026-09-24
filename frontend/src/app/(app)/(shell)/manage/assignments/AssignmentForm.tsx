"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, SelectField, TextareaField, TextField } from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { assignments, type Assignment, type AssignmentInput } from "@/lib/assignments";
import { userMessage } from "@/lib/errors";
import { isoToLocalDateTime, localDateTimeToIso } from "@/lib/labels";

/**
 * Writing a piece of homework — and, until this form, nothing in the product
 * could. `POST /manage/assignments` existed with its Action, its limiter and its
 * policy, the list told teachers «الواجب يُنشأ من صفحة الحصة أو الكورس», and no
 * such page ever offered it. An endpoint no screen calls is a feature nobody has.
 *
 * ⚠️ PUBLISHING IS NOT ON THIS FORM, deliberately. The server keeps it a separate
 * act (`SaveAssignment::publish`) so a half-written draft is never one save away
 * from locking the class out of the next session (FR-042). «انشره» on the row
 * is that act.
 */
export interface AssignmentFormState {
  title: string;
  description: string;
  points: string;
  dueAt: string;
  courseUuid: string;
  submissionType: "text" | "file";
  latePolicy: "accept" | "reject" | "penalty";
  penaltyPerDay: string;
  penaltyCap: string;
}

export const EMPTY_ASSIGNMENT: AssignmentFormState = {
  title: "",
  description: "",
  points: "10",
  dueAt: "",
  courseUuid: "",
  submissionType: "text",
  latePolicy: "accept",
  penaltyPerDay: "",
  penaltyCap: "100",
};

export function formFromAssignment(assignment: Assignment): AssignmentFormState {
  return {
    title: assignment.title,
    description: assignment.description ?? "",
    points: String(assignment.points),
    dueAt: isoToLocalDateTime(assignment.due_at),
    courseUuid: assignment.course?.uuid ?? "",
    // `questions` is refused by the server and never offered here.
    submissionType: assignment.submission_type === "file" ? "file" : "text",
    latePolicy: assignment.late_policy,
    penaltyPerDay: assignment.late_penalty_pct_per_day > 0 ? String(assignment.late_penalty_pct_per_day) : "",
    penaltyCap: String(assignment.late_penalty_cap_pct),
  };
}

/**
 * The request body, from the form.
 *
 * ⚠️ `due_at` IS CONVERTED, NEVER SENT RAW: the input is a naive wall clock and
 * the API runs on UTC — the same three-hour shift `manage/sessions` paid for.
 * And the penalty pair is sent only for the penalty policy: a rate left in the
 * box after switching back to «يُقبَل» is not something the teacher chose.
 */
export function assignmentPayload(form: AssignmentFormState): AssignmentInput {
  const penalty = form.latePolicy === "penalty";

  return {
    title: form.title.trim(),
    description: form.description.trim() === "" ? null : form.description,
    points: Number(form.points),
    due_at: form.dueAt === "" ? null : localDateTimeToIso(form.dueAt),
    course_uuid: form.courseUuid === "" ? null : form.courseUuid,
    submission_type: form.submissionType,
    late_policy: form.latePolicy,
    late_penalty_pct_per_day: penalty ? Number(form.penaltyPerDay || 0) : 0,
    late_penalty_cap_pct: penalty ? Number(form.penaltyCap || 100) : 100,
  };
}

export function AssignmentForm({
  editing,
  courses,
  onSaved,
  onCancel,
}: {
  /** Null for a new one. */
  editing: Assignment | null;
  courses: Array<{ uuid: string; title: string }>;
  onSaved: () => void;
  onCancel: () => void;
}) {
  const [form, setForm] = useState<AssignmentFormState>(
    editing === null ? EMPTY_ASSIGNMENT : formFromAssignment(editing),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  const set = <K extends keyof AssignmentFormState>(key: K, value: AssignmentFormState[K]) =>
    setForm((current) => ({ ...current, [key]: value }));

  const save = async () => {
    setSaving(true);
    setErrors({});
    setError("");

    try {
      const body = assignmentPayload(form);

      if (editing === null) await assignments.create(body);
      else await assignments.update(editing.uuid, body);

      onSaved();
    } catch (cause: unknown) {
      setErrors(fieldErrors(cause));
      setError(userMessage(cause));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card as="section">
      <h2 className="mb-4 font-medium text-ink">{editing === null ? "واجب جديد" : "تعديل الواجب"}</h2>

      <div className="space-y-4">
        <TextField
          id="assignment_title"
          label="العنوان"
          required
          value={form.title}
          onChange={(value) => set("title", value)}
          error={errors.title}
          maxLength={255}
        />

        <TextareaField
          id="assignment_description"
          label="المطلوب من الطالب"
          value={form.description}
          onChange={(value) => set("description", value)}
          error={errors.description}
          rows={4}
        />

        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <SelectField
            id="assignment_course"
            label="الكورس"
            value={form.courseUuid}
            onChange={(value) => set("courseUuid", value)}
            error={errors.course_uuid}
            hint="بلا كورس يصل الواجب إلى كل طلابك."
            options={[
              { value: "", label: "كل طلابي" },
              ...courses.map((course) => ({ value: course.uuid, label: course.title })),
            ]}
          />

          <TextField
            id="assignment_due_at"
            label="موعد التسليم"
            type="datetime-local"
            value={form.dueAt}
            onChange={(value) => set("dueAt", value)}
            error={errors.due_at}
            hint="لا يُنشر واجبٌ بلا موعد."
          />

          <NumberField
            id="assignment_points"
            label="الدرجة الكاملة"
            value={form.points}
            onChange={(value) => set("points", value)}
            error={errors.points}
            min={1}
            max={1000}
          />

          <SelectField
            id="assignment_submission_type"
            label="طريقة التسليم"
            value={form.submissionType}
            onChange={(value) => set("submissionType", value === "file" ? "file" : "text")}
            error={errors.submission_type}
            options={[
              { value: "text", label: "نص يكتبه الطالب" },
              { value: "file", label: "ملف يرفعه الطالب" },
            ]}
          />

          <SelectField
            id="assignment_late_policy"
            label="التسليم بعد الموعد"
            value={form.latePolicy}
            onChange={(value) =>
              set("latePolicy", value === "reject" ? "reject" : value === "penalty" ? "penalty" : "accept")
            }
            error={errors.late_policy}
            options={[
              { value: "accept", label: "يُقبَل بلا خصم" },
              { value: "reject", label: "لا يُقبَل" },
              { value: "penalty", label: "يُقبَل مع خصم" },
            ]}
          />
        </div>

        {form.latePolicy === "penalty" && (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <NumberField
              id="assignment_penalty_per_day"
              label="نسبة الخصم عن كل يوم (٪)"
              value={form.penaltyPerDay}
              onChange={(value) => set("penaltyPerDay", value)}
              error={errors.late_penalty_pct_per_day}
              min={0}
              max={100}
            />
            <NumberField
              id="assignment_penalty_cap"
              label="أقصى خصم (٪)"
              value={form.penaltyCap}
              onChange={(value) => set("penaltyCap", value)}
              error={errors.late_penalty_cap_pct}
              min={0}
              max={100}
            />
          </div>
        )}

        {error !== "" && <Alert tone="danger" title={error} />}

        <div className="flex flex-wrap gap-3">
          <Button onClick={save} loading={saving} loadingLabel="جارٍ الحفظ…">
            {editing === null ? "احفظ مسوّدة" : "احفظ التعديل"}
          </Button>
          <Button variant="ghost" onClick={onCancel}>
            إلغاء
          </Button>
        </div>
      </div>
    </Card>
  );
}
