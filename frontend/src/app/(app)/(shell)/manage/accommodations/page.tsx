"use client";

import { useCallback, useEffect, useState } from "react";

import {
  ClockIcon,
  ExtraDaysIcon,
  ExtraTimeIcon,
  IssuedDateIcon,
  SparkIcon,
  UserIcon,
} from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { NumberField, SelectField, TextareaField } from "@/components/ui/Field";
import { FilterBar } from "@/components/ui/FilterBar";
import { PageHeader } from "@/components/ui/PageHeader";
import { RecordList, RecordRow, type RecordMetaItem } from "@/components/ui/RecordList";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { accommodations, type Accommodation } from "@/lib/accommodations";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { counted, formatDate, NOUNS } from "@/lib/labels";
import { useTeacherStudents } from "@/lib/use-teacher-students";
import { arabicNumber } from "@/lib/numerals";
import { matchesSearch } from "@/lib/search-text";

/** «٣ ترتيبات» — the arrangements in force. */
const ARRANGEMENTS = { one: "ترتيب واحد", two: "ترتيبان", few: "ترتيبات", many: "ترتيباً", other: "ترتيب" };

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
 * next attempt with nothing telling them why. It is a row action, so it asks in
 * place — `ConfirmButton`, two presses (`docs/design/manage-pages.md` §8) — and
 * the consequence the old dialog spelled out is said once, over the list.
 */
export default function AccommodationsPage() {
  const { canPick: canPickStudents, students, failed: studentsFailed } = useTeacherStudents();

  const [rows, setRows] = useState<Accommodation[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");

  const [studentUuid, setStudentUuid] = useState("");
  const [extraTime, setExtraTime] = useState("");
  const [extraDays, setExtraDays] = useState("");
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  const [revoking, setRevoking] = useState<string | null>(null);
  const [revokeError, setRevokeError] = useState("");
  const [query, setQuery] = useState("");

  const load = useCallback(() => {
    setState("loading");

    accommodations
      .list()
      .then((response) => {
        setRows(response.data ?? []);
        setState("ready");
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

  const revoke = async (row: Accommodation) => {
    setRevoking(row.uuid);
    setRevokeError("");

    try {
      await accommodations.revoke(row.uuid);
      load();
    } catch (err: unknown) {
      setRevokeError(userMessage(err));
    } finally {
      setRevoking(null);
    }
  };

  const shown = rows.filter((row) => matchesSearch(query, row.student?.name, row.reason));

  // Zero in both is a row that grants nothing — an audit entry with no arrangement behind it.
  const nothingToGrant = Number(extraTime || 0) <= 0 && Number(extraDays || 0) <= 0;

  return (
    <div className="space-y-8">
      <PageHeader
        Icon={ClockIcon}
        title="ترتيبات خاصة للطلاب"
        description="وقتٌ إضافيّ في الاختبارات أو أيامٌ إضافية لتسليم الواجبات لطالبٍ بعينه. لا يراها زملاؤه؛ يرى هو مدّةً أطول وموعداً أبعد فقط."
      />

      <Card as="section" labelledBy="new-accommodation">
        <div className="mb-4">
          <SectionHeading id="new-accommodation" Icon={SparkIcon} title="ترتيبٌ جديد" />
        </div>

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

      <section aria-labelledby="active-accommodations" className="space-y-4">
        <SectionHeading
          id="active-accommodations"
          Icon={ClockIcon}
          title="الترتيبات السارية"
          description={
            state === "ready" && rows.length > 0
              ? `${counted(rows.length, ARRANGEMENTS)}. إلغاء أيٍّ منها يعيد الطالب إلى المدّة والمواعيد العادية من محاولته التالية.`
              : undefined
          }
        />

        {revokeError !== "" && <Alert tone="danger" title={revokeError} />}

        {state === "loading" && <RowsSkeleton count={3} />}

        {state === "error" && <ErrorState onRetry={load} />}

        {state === "ready" && rows.length === 0 && (
          <EmptyState
            Icon={ClockIcon}
            title="لا ترتيبات خاصة"
            description="كلّ طلابك يجلسون الاختبارات ويسلّمون الواجبات بالمواعيد نفسها. امنح أوّل ترتيب من النموذج أعلاه."
          />
        )}

        {state === "ready" && rows.length > 0 && (
          <>
            <FilterBar
              search={{
                id: "accommodation-search",
                label: "ابحث في الترتيبات",
                value: query,
                onChange: setQuery,
                placeholder: "اسم الطالب أو السبب",
              }}
              summary={counted(shown.length, ARRANGEMENTS)}
            />

            {shown.length === 0 ? (
              <EmptyState
                title="لا ترتيب يطابق البحث"
                description="جرّب اسماً آخر أو كلمةً من السبب."
                action={
                  <Button variant="secondary" size="sm" onClick={() => setQuery("")}>
                    مسح البحث
                  </Button>
                }
              />
            ) : (
              <RecordList labelledBy="active-accommodations">
                {shown.map((row) => {
                  const name = row.student?.name ?? "الطالب";
                  const meta: RecordMetaItem[] = [];

                  // A zero is an arrangement this row does not make, so it is left
                  // out rather than printed as «—» beside the one it does.
                  if (row.extra_time_pct > 0) {
                    meta.push({
                      key: "time",
                      label: "وقت إضافي في الاختبارات",
                      Icon: ExtraTimeIcon,
                      value: `${arabicNumber(row.extra_time_pct)}٪`,
                    });
                  }

                  if (row.extended_days > 0) {
                    meta.push({
                      key: "days",
                      label: "مهلة إضافية للواجبات",
                      Icon: ExtraDaysIcon,
                      value: counted(row.extended_days, NOUNS.days),
                    });
                  }

                  meta.push({ key: "granted", label: "منذ", Icon: IssuedDateIcon, value: formatDate(row.granted_at) });

                  return (
                    <RecordRow
                      key={row.uuid}
                      level={4}
                      Icon={UserIcon}
                      title={row.student?.name ?? "—"}
                      description={row.reason}
                      meta={meta}
                      actions={
                        <ConfirmButton
                          size="sm"
                          variant="secondary"
                          loading={revoking === row.uuid}
                          disabled={revoking !== null && revoking !== row.uuid}
                          confirmLabel="اضغط مجدداً لإلغاء الترتيب"
                          onConfirm={() => void revoke(row)}
                        >
                          إلغاء الترتيب <span className="sr-only">{`لـ${name}`}</span>
                        </ConfirmButton>
                      }
                    />
                  );
                })}
              </RecordList>
            )}
          </>
        )}
      </section>
    </div>
  );
}
