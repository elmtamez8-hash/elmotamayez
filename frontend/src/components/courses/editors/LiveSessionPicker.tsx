"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { SelectField } from "@/components/ui/Field";
import { errorMessage } from "@/lib/api";
import { courses, type LessonDetail, type ReferenceTargets } from "@/lib/courses";
import { formatSessionTime } from "@/lib/session-format";

/**
 * Holding a place in the sequence for a session that has not happened yet.
 *
 * The item is a POSITION, and the recording lands in it. Which is the whole
 * reason this exists: the automatic publication from spec 005 appends every
 * recording to a chapter at the end of the course, so a teacher who wanted
 * "الحصة الثالثة" between two lessons used to end up with a placeholder in the
 * middle and the recording at the end — two rows for one hour, and in a
 * sequential course a placeholder that nothing can ever complete.
 *
 * `state` is what this screen switches on, and `unavailable` is the state the
 * spec asks for by name (FR-048): the session's time has passed and no recording
 * ever arrived. Left as "coming soon" that row would promise a class that already
 * happened without them, forever.
 */
export function LiveSessionPicker({
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

  const reference = lesson.reference;
  const [selected, setSelected] = useState(reference?.uuid ?? "");

  useEffect(() => {
    courses
      .referenceTargets(courseUuid)
      .then(setTargets)
      .catch((err: unknown) => setError(errorMessage(err, "تعذّر تحميل حصص هذا الكورس.")));
  }, [courseUuid]);

  if (error !== "") {
    return (
      <Alert tone="danger" title="تعذّر تحميل الحصص">
        {error}
      </Alert>
    );
  }

  if (targets === null) return <p className="text-sm text-ink-muted">جارٍ تحميل الحصص…</p>;

  if (targets.sessions.length === 0) {
    return (
      <Alert tone="info" title="لا حصة مجدولة على هذا الكورس">
        اجدُل حصة أولاً، ثم ضع موضعها هنا ليهبط تسجيلها في مكانه من التسلسل بدل آخر الشجرة.
        <span className="mt-2 block">
          <Button href="/manage/sessions" size="sm" variant="secondary">
            جدول الحصص
          </Button>
        </span>
      </Alert>
    );
  }

  return (
    <div className="space-y-4">
      <SelectField
        id={`session-${lesson.uuid}`}
        label="الحصة"
        hint="حصص هذا الكورس. تسجيل الحصة سيحلّ في موضع هذا العنصر بالضبط، لا في آخر الشجرة."
        value={selected}
        disabled={disabled}
        placeholder="— اختر حصة —"
        options={targets.sessions.map((session) => ({
          value: session.uuid,
          label: `${session.title} — ${formatSessionTime(session.starts_at, session.timezone)} · ${session.status_label}`,
        }))}
        onChange={(value) => {
          setSelected(value);
          if (value !== "") onSave({ reference_uuid: value });
        }}
      />

      {reference !== null && "state" in reference && <SessionState state={reference.state} />}

      {reference === null && (
        <Alert tone="warning" title="هذا العنصر لا يشير إلى حصة بعد">
          اختر حصة قبل نشره — العنصر بلا حصة لا يُنشر.
        </Alert>
      )}
    </div>
  );
}

/**
 * Where this session is, in one sentence.
 *
 * Every state says what it means for the STUDENT looking at the row, because that
 * is the question the teacher is really asking when they check on it.
 */
function SessionState({
  state,
}: {
  state: "upcoming" | "processing" | "recorded" | "cancelled" | "unavailable";
}) {
  if (state === "recorded") {
    return (
      <Alert tone="success" title="التسجيل في مكانه">
        صار هذا العنصر تسجيل الحصة — يشاهده من حجز مقعداً فيها.
      </Alert>
    );
  }

  if (state === "processing") {
    return (
      <Alert tone="info" title="التسجيل قيد التجهيز">
        سيحلّ في موضع هذا العنصر تلقائياً عند جهوزه. لا شيء عليك فعله.
      </Alert>
    );
  }

  if (state === "cancelled") {
    return (
      <Alert tone="warning" title="الحصة ملغاة">
        لن يصل تسجيل لهذه الحصة. اختر حصة أخرى لهذا الموضع أو احذفه.
      </Alert>
    );
  }

  if (state === "unavailable") {
    return (
      // FR-048: a final, understandable state rather than a row that hangs on
      // "coming soon" after the class has already happened.
      <Alert tone="warning" title="مضى موعد الحصة بلا تسجيل">
        لا تسجيل لهذه الحصة، ولن يصل واحد تلقائياً. ارفع الفيديو بنفسك بتغيير نوع العنصر إلى
        «فيديو»، أو احذف الموضع.
      </Alert>
    );
  }

  return (
    <Alert tone="info" title="الحصة لم تبدأ بعد">
      يرى طلابك موعدها في مكانه من التسلسل، ويحلّ تسجيلها هنا بعد انتهائها.
    </Alert>
  );
}
