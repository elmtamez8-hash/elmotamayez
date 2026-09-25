"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { SelectField, TextareaField } from "@/components/ui/Field";
import { Table, type Column } from "@/components/ui/Table";
import { ApiError, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import { unlockRules, type UnlockExemption } from "@/lib/unlock-rules";
import { useTeacherStudents } from "@/lib/use-teacher-students";

/**
 * Let one student past the unlock condition on THIS session (FR-040).
 *
 * `POST /manage/class-sessions/{uuid}/unlock-exemptions` and `unlockRules.exempt()`
 * both existed and nothing called them: a student shut out by the rule — absent
 * for a funeral, say — stayed shut out, and the only lever the teacher had was
 * switching the rule off for the whole class.
 *
 * ⚠️ IT LIVES ON THE SESSION PAGE, NOT ON `/manage/unlock-rules`. An exemption is
 * one student on one session; the rules screen is about the condition, and has
 * no session to name. That screen points here instead.
 *
 * ⚠️ THE PICKER IS THE WHOLE CLASS, NOT THE REGISTER BELOW. A student the gate
 * refused could not book, so they hold no seat and appear on no register — the
 * one list on this page is exactly the one they are missing from.
 *
 * ⚠️ THE REASON IS REQUIRED, and the server refuses fewer than three
 * characters. It is the only answer to the parent who asks, six months later,
 * why their child was treated differently.
 */
export function UnlockExemptions({ sessionUuid }: { sessionUuid: string }) {
  const { canPick, students, failed: studentsFailed } = useTeacherStudents();

  const [rows, setRows] = useState<UnlockExemption[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "empty" | "error">("loading");

  const [studentUuid, setStudentUuid] = useState("");
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  const load = useCallback(() => {
    setState("loading");

    unlockRules
      .exemptions(sessionUuid)
      .then((response) => {
        const data = response.data ?? [];
        setRows(data);
        setState(data.length === 0 ? "empty" : "ready");
      })
      .catch(() => setState("error"));
  }, [sessionUuid]);

  useEffect(load, [load]);

  const grant = async () => {
    setSaving(true);
    setError("");
    setErrors({});
    setSaved(false);

    try {
      await unlockRules.exempt(sessionUuid, studentUuid, reason.trim());
      setStudentUuid("");
      setReason("");
      setSaved(true);
      load();
    } catch (err: unknown) {
      const fields = fieldErrors(err);

      if (Object.keys(fields).length > 0) {
        setErrors(fields);
      } else if (err instanceof ApiError && err.status === 404) {
        setError("هذا الطالب لم يعد مسجّلاً عندك، فلا يُستثنى. أعد تحميل الصفحة.");
      } else {
        setError(userMessage(err));
      }
    } finally {
      setSaving(false);
    }
  };

  const columns: Column<UnlockExemption>[] = [
    { key: "student", header: "الطالب", render: (row) => row.student?.name ?? "—" },
    { key: "reason", header: "السبب", render: (row) => row.reason },
    { key: "granted", header: "منذ", render: (row) => (row.granted_at === null ? "—" : formatDate(row.granted_at)) },
  ];

  return (
    <div className="space-y-4">
      {!canPick ? (
        <Alert tone="info" title="اختيار الطالب غير متاح لحسابك">
          تُقرأ قائمة طلابك من لوحة أرصدتهم، وحسابك لا يملك صلاحية عرضها. اطلب من المدرّس صاحب الحساب منحها لك.
        </Alert>
      ) : (
        <div className="space-y-3">
          {studentsFailed && <Alert tone="danger" title="تعذّر تحميل قائمة طلابك. أعد تحميل الصفحة." />}
          {error !== "" && <Alert tone="danger" title={error} />}
          {saved && <Alert tone="success" title="سُجّل الاستثناء، ويستطيع الطالب حجز هذه الحصة ودخولها الآن." />}

          <SelectField
            id="exemption_student"
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

          <TextareaField
            id="exemption_reason"
            label="السبب"
            required
            rows={2}
            hint="يُحفظ مع الاستثناء: من سمح ولماذا."
            value={reason}
            onChange={setReason}
            error={errors.reason}
          />

          <Button
            variant="secondary"
            onClick={() => void grant()}
            loading={saving}
            loadingLabel="جارٍ الحفظ…"
            disabled={studentUuid === "" || reason.trim().length < 3}
          >
            استثنِ الطالب
          </Button>
        </div>
      )}

      <Table
        caption="المستثنَون من شرط الفتح في هذه الحصة"
        columns={columns}
        rows={rows}
        rowKey={(row) => row.uuid}
        state={state}
        emptyTitle="لا استثناءات"
        emptyDescription="كلّ طلابك يخضعون لشرط الفتح في هذه الحصة."
        onRetry={load}
      />
    </div>
  );
}
