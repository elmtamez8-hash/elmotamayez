"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckboxField, SelectField, TextField } from "@/components/ui/Field";
import { errorMessage, fieldErrors } from "@/lib/api";
import { family, GUARDIAN_PERMISSIONS, type GuardianRelation } from "@/lib/notifications";

/**
 * Guardians and the students they follow.
 *
 * Permissions are shown as what they mean ("الحضور والغياب"), not as flags: the
 * person setting them is a parent deciding what an uncle may see, not an admin
 * reading an enum.
 */
export default function FamilyPage() {
  const [relations, setRelations] = useState<GuardianRelation[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [name, setName] = useState("");
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

    try {
      await family.add({ student_name: name, relation_type: relationType, permissions });
      setName("");
      await load();
    } catch (err) {
      const fields = fieldErrors(err);
      setError(Object.values(fields)[0] ?? errorMessage(err, "تعذّرت الإضافة."));
    } finally {
      setSubmitting(false);
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

  if (loading) return <p className="text-ink-muted">جارٍ التحميل…</p>;

  return (
    <div className="mx-auto max-w-2xl space-y-8">
      <h2 className="text-2xl font-bold text-ink">وليّ الأمر والأوصياء</h2>

      {error && <Alert tone="danger" title={error} />}

      <Card as="section">
        <h3 className="mb-4 font-semibold text-ink">المرتبطون</h3>

        {relations.length === 0 ? (
          <p className="py-6 text-center text-ink-muted">لا يوجد مرتبطون بعد.</p>
        ) : (
          <ul className="space-y-3">
            {relations.map((relation) => (
              <li key={relation.uuid} className="rounded-xl border border-line p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="font-semibold text-ink">{relation.student_name}</p>
                    <p className="mt-1 text-xs text-ink-muted">
                      {relation.relation_type_label} · {relation.status_label}
                      {!relation.student_has_account && " · لا يملك حساباً بعد"}
                    </p>
                    <ul className="mt-2 flex flex-wrap gap-1.5">
                      {relation.permissions.map((permission) => (
                        <li
                          key={permission.key}
                          className="rounded bg-primary-soft px-2 py-0.5 text-xs text-primary-ink"
                        >
                          {permission.label}
                        </li>
                      ))}
                    </ul>
                  </div>

                  {relation.status !== "revoked" && (
                    <Button
                      variant="danger"
                      size="sm"
                      onClick={() => revoke(relation.uuid)}
                    >
                      إلغاء الارتباط
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card as="section">
        <h3 className="mb-4 font-semibold text-ink">إضافة مرتبط</h3>

        <div className="space-y-4">
          <TextField
            id="student_name"
            label="اسم الطالب"
            value={name}
            onChange={setName}
            required
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
