"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { EyeIcon, FamilyIcon, ShieldIcon, UserPlusIcon } from "@/components/icons";
import { CheckboxField, SelectField, TextField } from "@/components/ui/Field";
import { errorMessage, fieldErrors } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { family, GUARDIAN_PERMISSIONS, type GuardianRelation } from "@/lib/notifications";

import { RelationRow } from "./RelationRow";

/**
 * Guardians and the students they follow — FROM BOTH SIDES.
 *
 * ⚠️ THIS SCREEN WAS WRITTEN ENTIRELY FROM THE GUARDIAN'S ANGLE, and the reader it
 * failed was the one the whole feature depends on. `/family` has no audience gate,
 * so a student has always reached it — and read a row headed with THEIR OWN NAME,
 * with one button on it saying «إلغاء الارتباط». The link waiting on them was
 * invisible, and there was no route in the product that could activate it.
 *
 * Permissions are shown as what they mean ("الحضور والغياب"), not as flags: the
 * person setting them is a parent deciding what an uncle may see, not an admin
 * reading an enum.
 */
export default function FamilyPage() {
  const { user } = useAuth();
  const [relations, setRelations] = useState<GuardianRelation[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [name, setName] = useState("");
  const [studentCode, setStudentCode] = useState("");
  const [requested, setRequested] = useState(false);
  const [copied, setCopied] = useState(false);
  const [relationType, setRelationType] = useState<"parent" | "guardian">("parent");
  const [permissions, setPermissions] = useState<string[]>(
    GUARDIAN_PERMISSIONS.map((permission) => permission.key),
  );
  const [submitting, setSubmitting] = useState(false);

  const load = useCallback(async () => {
    try {
      const result = await family.list();
      setRelations(result.data ?? []);
    } catch (err) {
      setError(errorMessage(err, "تعذّر تحميل قائمة المرتبطين."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const add = async () => {
    setSubmitting(true);
    setError("");
    setRequested(false);

    const code = studentCode.trim();

    try {
      /*
       * ⚠️ THE CODE IS WHAT MAKES THIS A LINK TO A REAL ACCOUNT. Without it the
       * row is a name-only child — `active` at once, with no account behind it,
       * so `ChildSwitcher` (rightly) never offers it and no report, attendance
       * or balance can ever be read. That was the only thing this form could
       * send, so no parent could reach a child who had signed up themselves.
       *
       * Omitted rather than `""` when empty: the server's `uuid` rule refuses an
       * empty string.
       */
      await family.add({
        student_name: name,
        relation_type: relationType,
        permissions,
        ...(code === "" ? {} : { student_uuid: code }),
      });
      setName("");
      setStudentCode("");
      setRequested(code !== "");
      await load();
    } catch (err) {
      const fields = fieldErrors(err);
      setError(Object.values(fields)[0] ?? errorMessage(err, "تعذّرت الإضافة."));
    } finally {
      setSubmitting(false);
    }
  };

  const copyCode = async (code: string) => {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
    } catch {
      // Refused outside a secure context and in some in-app browsers. The code
      // is on screen and selectable either way — not an error worth a banner.
      setCopied(false);
    }
  };

  const revoke = async (uuid: string) => {
    try {
      await family.revoke(uuid);
      await load();
    } catch (err) {
      setError(errorMessage(err, "تعذّر إلغاء الارتباط."));
    }
  };

  const accept = async (uuid: string) => {
    setError("");

    try {
      await family.accept(uuid);
      await load();
    } catch (err) {
      setError(errorMessage(err, "تعذّر قبول الطلب."));
    }
  };

  const savePermissions = async (uuid: string, permissions: string[]) => {
    setError("");

    try {
      await family.updatePermissions(uuid, permissions);
      await load();
    } catch (err) {
      // The server refuses a guardian widening their own reach with an Arabic
      // sentence of its own; `errorMessage` is what puts it on the screen instead
      // of a raw error.
      setError(errorMessage(err, "تعذّر حفظ الصلاحيات."));
    }
  };

  if (loading) return <p className="text-ink-muted">جارٍ التحميل…</p>;

  /*
   * Split by the SERVER'S answer. `viewer_side` is null for a teacher reading a
   * student's guardians through an active enrolment — neither list is theirs, and
   * a two-value union would have told them they were the student.
   */
  const mine = relations.filter((relation) => relation.viewer_side === "guardian");
  const following = relations.filter((relation) => relation.viewer_side === "student");

  return (
    <div className="mx-auto max-w-2xl space-y-8">
      <PageHeader Icon={FamilyIcon} title="وليّ الأمر والأوصياء" />

      {error && <Alert tone="danger" title={error} />}

      {/*
        The FIRST STEP of linking a child who already has an account, and it had
        no screen: the server has taken a `student_uuid` since spec 030, and no
        student could see theirs. It is the student's own identifier, shown to
        them alone; handing it to a parent lets that parent ASK, never see — the
        link stays pending until the student accepts it below.
      */}
      {user?.platform_role === "student" && user.uuid && (
        <Card as="section">
          <div className="mb-2">
            <SectionHeading id="family-code" Icon={ShieldIcon} title="رمز ربط حسابي" />
          </div>
          <p className="mb-3 text-sm text-ink-muted">
            أرسِلْ هذا الرمز لوليّ أمرك ليُدخِله في صفحته. يصلك طلبه هنا فتقبله أو ترفضه، ولا
            يرى شيئاً من حسابك قبل موافقتك.
          </p>
          <p className="mb-3 break-all font-mono text-sm text-ink select-all" dir="ltr">
            {user.uuid}
          </p>
          <div className="flex items-center gap-3">
            <Button type="button" variant="secondary" onClick={() => void copyCode(user.uuid)}>
              نسخ الرمز
            </Button>
            {copied && <span className="text-sm text-secondary-ink">نُسخ.</span>}
          </div>
        </Card>
      )}

      {/*
        The student's half, and it comes FIRST when there is something waiting: a
        request nobody can see is a request nobody answers, which is exactly how
        this shipped.
      */}
      {following.length > 0 && (
        <Card as="section">
          <div className="mb-4">
            <SectionHeading id="family-following" Icon={EyeIcon} title="من يتابعني" />
          </div>

          <ul className="space-y-3">
            {following.map((relation) => (
              <RelationRow
                key={relation.uuid}
                relation={relation}
                onAccept={accept}
                onRevoke={revoke}
                onSavePermissions={savePermissions}
              />
            ))}
          </ul>
        </Card>
      )}

      <Card as="section">
        <div className="mb-4">
          <SectionHeading id="family-linked" Icon={FamilyIcon} title="المرتبطون" />
        </div>

        {mine.length === 0 ? (
          <p className="py-6 text-center text-ink-muted">لا يوجد مرتبطون بعد.</p>
        ) : (
          <ul className="space-y-3">
            {mine.map((relation) => (
              <RelationRow
                key={relation.uuid}
                relation={relation}
                onAccept={accept}
                onRevoke={revoke}
                onSavePermissions={savePermissions}
              />
            ))}
          </ul>
        )}
      </Card>

      <Card as="section">
        <div className="mb-4">
          <SectionHeading id="family-add" Icon={UserPlusIcon} title="إضافة مرتبط" />
        </div>

        <div className="space-y-4">
          <TextField
            id="student_name"
            label="اسم الطالب"
            value={name}
            onChange={setName}
            required
          />

          <TextField
            id="student_uuid"
            label="رمز حساب الطالب — إن كان له حساب على المنصّة"
            value={studentCode}
            onChange={setStudentCode}
            hint="يجده ابنك في صفحة «وليّ الأمر والأوصياء» من حسابه. يصله طلبك ليوافق عليه، ولا ترى شيئاً قبل موافقته."
          />

          <SelectField
            id="relation_type"
            label="صفة الارتباط"
            value={relationType}
            onChange={(value) => setRelationType(value as "parent" | "guardian")}
            options={[
              { value: "parent", label: "وليّ أمر" },
              { value: "guardian", label: "وصيّ" },
            ]}
            hint="لكل طالب وليّ أمر واحد، وأوصياء بلا عدد."
          />

          <fieldset>
            <legend className="mb-2 text-sm font-medium text-ink">
              ما يطّلع عليه ويستقبله
            </legend>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {GUARDIAN_PERMISSIONS.map((permission) => (
                <CheckboxField
                  key={permission.key}
                  id={`permission-${permission.key}`}
                  label={permission.label}
                  checked={permissions.includes(permission.key)}
                  onChange={(checked) =>
                    setPermissions((current) =>
                      checked
                        ? [...current, permission.key]
                        : current.filter((value) => value !== permission.key),
                    )
                  }
                />
              ))}
            </div>
          </fieldset>

          {requested && (
            <Alert tone="info" title="أُرسل الطلب إلى حساب الطالب">
              يظهر في قائمتك هنا، وتصلك بياناته بعد أن يوافق عليه من حسابه.
            </Alert>
          )}

          <Button
            onClick={add}
            loading={submitting}
            loadingLabel="جارٍ الإضافة…"
            disabled={name.trim() === "" || permissions.length === 0}
          >
            إضافة
          </Button>
        </div>
      </Card>
    </div>
  );
}
