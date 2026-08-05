"use client";

import { useCallback, useEffect, useState } from "react";

import { errorMessage, fieldErrors } from "@/lib/api";
import {
  family,
  GUARDIAN_PERMISSIONS,
  type GuardianRelation,
} from "@/lib/notifications";

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
  const [error, setError] = useState<string | null>(null);

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
    setError(null);

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
    <div className="mx-auto max-w-3xl">
      {error && (
        <p role="alert" className="mb-4 rounded-lg border border-danger bg-surface-raised px-4 py-3 text-sm text-danger-ink">
          {error}
        </p>
      )}

      <section className="mb-8">
        <h2 className="mb-3 text-base font-semibold text-ink">المرتبطون</h2>

        {relations.length === 0 && (
          <p className="rounded-lg border border-line bg-surface-raised px-4 py-10 text-center text-ink-muted">
            لا يوجد مرتبطون بعد.
          </p>
        )}

        <ul className="space-y-3">
          {relations.map((relation) => (
            <li key={relation.uuid} className="rounded-lg border border-line bg-surface-raised p-4">
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
                  <button
                    type="button"
                    onClick={() => revoke(relation.uuid)}
                    className="shrink-0 rounded-lg border border-danger px-3 py-1.5 text-sm text-danger-ink transition hover:bg-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    إلغاء الارتباط
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      </section>

      <section className="rounded-lg border border-line bg-surface-raised p-4">
        <h2 className="mb-4 text-base font-semibold text-ink">إضافة مرتبط</h2>

        <div className="space-y-4">
          <label className="block text-sm text-ink">
            اسم الطالب
            <input
              type="text"
              value={name}
              onChange={(event) => setName(event.target.value)}
              className="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"
            />
          </label>

          <label className="block text-sm text-ink">
            صفة الارتباط
            <select
              value={relationType}
              onChange={(event) => setRelationType(event.target.value as "parent" | "guardian")}
              className="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"
            >
              <option value="parent">وليّ أمر</option>
              <option value="guardian">وصيّ</option>
            </select>
          </label>

          <fieldset>
            <legend className="mb-2 text-sm text-ink">ما يطّلع عليه ويستقبله</legend>
            <div className="flex flex-wrap gap-3">
              {GUARDIAN_PERMISSIONS.map((permission) => (
                <label key={permission.key} className="flex items-center gap-2 text-sm text-ink">
                  <input
                    type="checkbox"
                    checked={permissions.includes(permission.key)}
                    onChange={() =>
                      setPermissions((current) =>
                        current.includes(permission.key)
                          ? current.filter((value) => value !== permission.key)
                          : [...current, permission.key],
                      )
                    }
                    className="h-4 w-4 accent-primary"
                  />
                  {permission.label}
                </label>
              ))}
            </div>
          </fieldset>

          <button
            type="button"
            onClick={add}
            disabled={submitting || name.trim() === "" || permissions.length === 0}
            className="rounded-lg bg-primary px-4 py-2 font-medium text-white transition hover:opacity-90 disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            {submitting ? "جارٍ الإضافة…" : "إضافة"}
          </button>
        </div>
      </section>
    </div>
  );
}
