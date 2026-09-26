"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, SelectField, TextareaField } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { Table, type Column } from "@/components/ui/Table";
import { ClockIcon } from "@/components/icons";
import { accommodations, type Accommodation } from "@/lib/accommodations";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { counted, formatDate } from "@/lib/labels";
import { useTeacherStudents } from "@/lib/use-teacher-students";
import { arabicNumber } from "@/lib/numerals";

/**
 * Extra time and extra days for one student (spec 008 · FR-053 · FR-055).
 *
 * ⚠️ THE PICKER OFFERS THE TEACHER'S OWN STUDENTS ONLY. The server answers 404
 * both for «no such person» and «not your student» — on purpose, so a uuid can
 * not be probed — which means a free-typed uuid would fail with a sentence that
 * cannot say why. The list comes from `useTeacherStudents()`, which reads the
 * same predicate the server checks.
 *
 * ⚠️ AND A REVOCATION ASKS FIRST. The student's longer timer disappears on their
 * next attempt with nothing telling them why.
 */
export default function AccommodationsPage() {
  const { canPick: canPickStudents, students, failed: studentsFailed } = useTeacherStudents();

  const [rows, setRows] = useState<Accommodation[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "empty" | "error">("loading");

  const [studentUuid, setStudentUuid] = useState("");
  const [extraTime, setExtraTime] = useState("");
  const [extraDays, setExtraDays] = useState("");
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  const [revoking, setRevoking] = useState<Accommodation | null>(null);
  const [revokeBusy, setRevokeBusy] = useState(false);
  const [revokeError, setRevokeError] = useState("");

  const load = useCallback(() => {
    setState("loading");

    accommodations
      .list()
      .then((response) => {
        const data = response.data ?? [];
        setRows(data);
        setState(data.length === 0 ? "empty" : "ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  const grant = async () => {
    setSaving(true);
    setError("");
    setErrors({});
    setSaved(false);

    try {
      await accommodations.grant({
        student_uuid: studentUuid,
        extra_time_pct: Number(extraTime || 0),
        extended_days: Number(extraDays || 0),
        reason: reason.trim(),
      });

      setStudentUuid("");
      setExtraTime("");
      setExtraDays("");
      setReason("");
      setSaved(true);
      load();
    } catch (err: unknown) {
      const fields = fieldErrors(err);

      if (Object.keys(fields).length > 0) {
        setErrors(fields);
      } else {
        setError(userMessage(err));
      }
    } finally {
      setSaving(false);
    }
  };

  const revoke = async () => {
    if (revoking === null) return;

    setRevokeBusy(true);
    setRevokeError("");

    try {
      await accommodations.revoke(revoking.uuid);
      setRevoking(null);
      load();
    } catch (err: unknown) {
      setRevokeError(userMessage(err));
      setRevoking(null);
    } finally {
      setRevokeBusy(false);
    }
  };

  const columns: Column<Accommodation>[] = [
    { key: "student", header: "الطالب", render: (row) => row.student?.name ?? "—" },
    {
      key: "time",
      header: "وقت إضافي في الاختبارات",
      render: (row) => (row.extra_time_pct > 0 ? <bdi>{`${arabicNumber(row.extra_time_pct)}٪`}</bdi> : "—"),
    },
    {
      key: "days",
      header: "مهلة إضافية للواجبات",
      render: (row) =>
        row.extended_days > 0
          ? counted(row.extended_days, { one: "يوم واحد", two: "يومان", few: "أيام", many: "يوماً", other: "يوم" })
          : "—",
    },
    { key: "reason", header: "السبب", render: (row) => row.reason },
    { key: "granted", header: "منذ", render: (row) => formatDate(row.granted_at) },
    {
      key: "actions",
      header: "الإجراء",
      render: (row) => (
        <Button variant="ghost" size="sm" onClick={() => setRevoking(row)}>
          إلغاء الترتيب <span className="sr-only">{`لـ${row.student?.name ?? "الطالب"}`}</span>
        </Button>
      ),
    },
  ];

  // Zero in both is a row that grants nothing — an audit entry with no arrangement behind it.
  const nothingToGrant = Number(extraTime || 0) <= 0 && Number(extraDays || 0) <= 0;

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={ClockIcon}
        title="ترتيبات خاصة للطلاب"
        description="وقتٌ إضافيّ في الاختبارات أو أيامٌ إضافية لتسليم الواجبات لطالبٍ بعينه. لا يراها زملاؤه؛ يرى هو مدّةً أطول وموعداً أبعد فقط."
      />

      <Card as="section">
        <h2 className="mb-4 text-base font-semibold text-ink">ترتيبٌ جديد</h2>

        {!canPickStudents ? (
          <Alert tone="info" title="اختيار الطالب غير متاح لحسابك">
            تُقرأ قائمة طلابك من لوحة أرصدتهم، وحسابك لا يملك صلاحية عرضها. اطلب من المدرّس صاحب الحساب منحها لك.
          </Alert>
        ) : (
          <div className="space-y-4">
            {studentsFailed && <Alert tone="danger" title="تعذّر تحميل قائمة طلابك. أعد تحميل الصفحة." />}
            {error !== "" && <Alert tone="danger" title={error} />}
            {saved && <Alert tone="success" title="حُفظ الترتيب، ويسري من المحاولة التالية." />}

            <SelectField
              id="student_uuid"
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

            <div className="grid gap-4 sm:grid-cols-2">
              <NumberField
                id="extra_time_pct"
                label="وقت إضافي في الاختبارات (٪)"
                hint="من ٠ إلى ٢٠٠. ٥٠ تعني نصف المدّة زيادة."
                value={extraTime}
                onChange={setExtraTime}
                min={0}
                max={200}
                step={5}
                error={errors.extra_time_pct}
              />
              <NumberField
                id="extended_days"
                label="أيام إضافية لتسليم الواجبات"
                hint="من ٠ إلى ٣٠."
                value={extraDays}
                onChange={setExtraDays}
                min={0}
                max={30}
                step={1}
                error={errors.extended_days}
              />
            </div>

            <TextareaField
              id="reason"
              label="السبب"
              required
              rows={2}
              hint="يُحفظ مع الترتيب: من منحه ولماذا."
              value={reason}
              onChange={setReason}
              error={errors.reason}
            />

            <Button
              onClick={() => void grant()}
              loading={saving}
              loadingLabel="جارٍ الحفظ…"
              disabled={studentUuid === "" || reason.trim() === "" || nothingToGrant}
            >
              احفظ الترتيب
            </Button>
          </div>
        )}
      </Card>

      {revokeError !== "" && <Alert tone="danger" title={revokeError} />}

      <Table
        caption="الترتيبات الخاصة السارية"
        columns={columns}
        rows={rows}
        rowKey={(row) => row.uuid}
        state={state}
        emptyTitle="لا ترتيبات خاصة"
        emptyDescription="كلّ طلابك يجلسون الاختبارات ويسلّمون الواجبات بالمواعيد نفسها."
        onRetry={load}
      />

      <Modal
        open={revoking !== null}
        title="إلغاء الترتيب"
        message={`يعود ${revoking?.student?.name ?? "الطالب"} إلى المدّة والمواعيد العادية من محاولته التالية.`}
        confirmLabel="ألغِ الترتيب"
        tone="danger"
        busy={revokeBusy}
        onConfirm={() => void revoke()}
        onCancel={() => setRevoking(null)}
      />
    </div>
  );
}
