"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { SelectField, TextField } from "@/components/ui/Field";
import { ApiError, fieldErrors } from "@/lib/api";
import { assignments } from "@/lib/assignments";
import { userMessage } from "@/lib/errors";
import { localDateTimeToIso } from "@/lib/labels";
import { useTeacherStudents } from "@/lib/use-teacher-students";

/**
 * A later deadline for one student on one assignment (FR-047).
 *
 * `POST /manage/assignments/{uuid}/extensions` had its Action, its request and
 * its tests, and nothing in `frontend/src` called it — so the only way a
 * teacher could give one student more time was a standing accommodation that
 * moved EVERY deadline for them.
 *
 * ⚠️ THE PAST IS REFUSED HERE AS WELL AS THERE. The server's `after:now` answers
 * with a field error, but a teacher picking «today 09:00» at 14:00 deserves the
 * sentence before the round trip, not a red box after it.
 *
 * ⚠️ AND A 404 IS TRANSLATED, NOT SHOWN. The server answers «not your student»
 * and «nobody» identically on purpose; the only way to reach it from this
 * picker is a student whose enrolment ended since the list loaded, and that is
 * what the sentence says.
 */
export function ExtensionForm({
  assignmentUuid,
  onGranted,
  now = () => new Date(),
}: {
  assignmentUuid: string;
  onGranted: () => void;
  /** Injected so a test can pin «now» without faking the clock. */
  now?: () => Date;
}) {
  const { canPick, students, failed } = useTeacherStudents();

  const [studentUuid, setStudentUuid] = useState("");
  const [until, setUntil] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [granted, setGranted] = useState("");

  if (!canPick) {
    return (
      <Alert tone="info" title="منح المهلة غير متاح لحسابك">
        تُقرأ قائمة طلابك من لوحة أرصدتهم، وحسابك لا يملك صلاحية عرضها. اطلب من المدرّس صاحب الحساب منحها لك.
      </Alert>
    );
  }

  const grant = async () => {
    setError("");
    setErrors({});
    setGranted("");

    const at = new Date(until);

    if (Number.isNaN(at.getTime()) || at.getTime() <= now().getTime()) {
      setErrors({ until: "اختر موعداً لم يمضِ بعد." });

      return;
    }

    setSaving(true);

    try {
      await assignments.extend(assignmentUuid, {
        student_uuid: studentUuid,
        until: localDateTimeToIso(until),
      });

      const name = students?.find((student) => student.uuid === studentUuid)?.name ?? "الطالب";

      setGranted(`مُنح ${name} مهلةً جديدة.`);
      setStudentUuid("");
      setUntil("");
      onGranted();
    } catch (err: unknown) {
      const fields = fieldErrors(err);

      if (Object.keys(fields).length > 0) {
        setErrors(fields);
      } else if (err instanceof ApiError && err.status === 404) {
        setError("هذا الطالب لم يعد مسجّلاً عندك، فلا تُمنح له مهلة. أعد تحميل الصفحة.");
      } else {
        setError(userMessage(err));
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-3 rounded-lg border border-line p-4">
      <h4 className="text-sm font-semibold text-ink">مهلة لطالب بعينه</h4>
      <p className="text-xs text-ink-muted">
        موعدٌ أبعد لهذا الواجب وحده، لا يراه زملاؤه. منحُ مهلةٍ ثانية للطالب نفسه يستبدل الأولى.
      </p>

      {failed && <Alert tone="danger" title="تعذّر تحميل قائمة طلابك. أعد تحميل الصفحة." />}
      {error !== "" && <Alert tone="danger" title={error} />}
      {granted !== "" && <Alert tone="success" title={granted} />}

      <div className="grid gap-3 sm:grid-cols-2">
        <SelectField
          id={`extension-student-${assignmentUuid}`}
          label="الطالب"
          required
          value={studentUuid}
          onChange={setStudentUuid}
          placeholder={students === null ? "جارٍ تحميل طلابك…" : "اختر طالباً"}
          options={(students ?? []).map((student) => ({ value: student.uuid, label: student.name }))}
          disabled={students === null}
          error={errors.student_uuid}
          hint={students !== null && students.length === 0 ? "لا طلاب مسجَّلين عندك الآن." : undefined}
        />
        <TextField
          id={`extension-until-${assignmentUuid}`}
          label="الموعد الجديد"
          type="datetime-local"
          required
          value={until}
          onChange={setUntil}
          error={errors.until}
        />
      </div>

      <Button
        variant="secondary"
        onClick={() => void grant()}
        loading={saving}
        loadingLabel="جارٍ الحفظ…"
        disabled={studentUuid === "" || until === ""}
      >
        امنح المهلة
      </Button>
    </div>
  );
}
