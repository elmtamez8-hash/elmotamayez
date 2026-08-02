"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, errorMessage, fieldErrors } from "@/lib/api";
import type { ChildLink } from "@/lib/types";
import type { Taxonomy } from "@/lib/public-api";
import { SubmitButton } from "./SubmitButton";
import { NotificationPreferences } from "./NotificationPreferences";

const FIELD_CLASS =
  "w-full rounded-xl border border-line bg-surface px-3 py-2.5 text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary";

/**
 * Add a child, or skip.
 *
 * Skipping is a first-class option: a parent who wants to look around before
 * naming their child should not be blocked, and the same screen is reachable
 * again from the account (FR-074).
 */
export function AddChildForm({ gradeLevels }: { gradeLevels: Taxonomy[] }) {
  const router = useRouter();

  const [children, setChildren] = useState<ChildLink[]>([]);
  const [form, setForm] = useState({ name: "", age: "", grade_level_slug: "" });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api
      .get<{ data: ChildLink[] }>("/parent/children")
      .then((res) => setChildren(res.data))
      .catch(() => setBanner("تعذّر تحميل قائمة الأبناء."));
  }, []);

  const set = (key: keyof typeof form, value: string) =>
    setForm((current) => ({ ...current, [key]: value }));

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setErrors({});
    setBanner("");
    setLoading(true);

    try {
      const created = await api.post<ChildLink>("/parent/children", {
        name: form.name,
        age: form.age === "" ? null : Number(form.age),
        grade_level_slug: form.grade_level_slug === "" ? null : form.grade_level_slug,
      });

      setChildren((current) => [...current, created]);
      setForm({ name: "", age: "", grade_level_slug: "" });
    } catch (err: unknown) {
      const fields = fieldErrors(err);
      setErrors(fields);

      if (Object.keys(fields).length === 0) {
        setBanner(errorMessage(err, "تعذّر إضافة الطالب، حاول مرة أخرى."));
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="space-y-8">
      {children.length > 0 && (
        <section aria-labelledby="children-heading">
          <h2 id="children-heading" className="mb-3 text-lg font-bold text-ink">
            الأبناء المضافون
          </h2>
          <ul className="space-y-2">
            {children.map((child) => (
              <li
                key={child.uuid}
                className="flex items-center justify-between gap-3 rounded-xl border border-line px-4 py-3"
              >
                <span className="font-medium text-ink">{child.name}</span>
                <span className="text-sm text-ink-muted">
                  {child.age === null ? "—" : `${child.age} سنة`}
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <form onSubmit={submit} noValidate className="space-y-5">
        <h2 className="text-lg font-bold text-ink">إضافة طفل</h2>

        {banner && (
          <p role="alert" className="rounded-xl bg-danger/10 p-3 text-sm text-danger-ink">
            {banner}
          </p>
        )}

        <div>
          <label htmlFor="child-name" className="mb-1 block text-sm font-medium text-ink">
            اسم الطالب
          </label>
          <input
            id="child-name"
            value={form.name}
            onChange={(e) => set("name", e.target.value)}
            required
            aria-invalid={errors.name ? true : undefined}
            aria-describedby={errors.name ? "child-name-error" : undefined}
            className={FIELD_CLASS}
          />
          {errors.name && (
            <p id="child-name-error" className="mt-1 text-sm text-danger-ink">
              {errors.name}
            </p>
          )}
        </div>

        <div className="grid gap-5 sm:grid-cols-2">
          <div>
            <label htmlFor="child-age" className="mb-1 block text-sm font-medium text-ink">
              العمر
            </label>
            <input
              id="child-age"
              type="number"
              min={3}
              max={25}
              value={form.age}
              onChange={(e) => set("age", e.target.value)}
              aria-invalid={errors.age ? true : undefined}
              className={FIELD_CLASS}
            />
            {errors.age && <p className="mt-1 text-sm text-danger-ink">{errors.age}</p>}
          </div>

          <div>
            <label htmlFor="child-grade" className="mb-1 block text-sm font-medium text-ink">
              المرحلة الدراسية
            </label>
            <select
              id="child-grade"
              value={form.grade_level_slug}
              onChange={(e) => set("grade_level_slug", e.target.value)}
              className={FIELD_CLASS}
            >
              <option value="">اختر المرحلة</option>
              {gradeLevels.map((level) => (
                <option key={level.slug} value={level.slug}>
                  {level.name_ar}
                </option>
              ))}
            </select>
          </div>
        </div>

        <SubmitButton loading={loading} loadingLabel="جارٍ الإضافة…">
          إضافة الطفل
        </SubmitButton>
      </form>

      <NotificationPreferences />

      <div className="flex flex-wrap justify-between gap-3 border-t border-line pt-6">
        <Link href="/teachers" className="text-sm font-semibold text-primary-ink hover:underline">
          تخطّي الآن وتصفّح المدرّسين
        </Link>
        <button
          type="button"
          onClick={() => router.push("/teachers")}
          className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
        >
          إنهاء
        </button>
      </div>
    </div>
  );
}
