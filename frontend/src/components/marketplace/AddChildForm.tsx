"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { api, errorMessage, fieldErrors } from "@/lib/api";
import type { ChildLink } from "@/lib/types";
import { childLinkName } from "@/lib/notifications";
import type { SchoolYearOption } from "@/lib/public-api";
import { Button } from "@/components/ui/Button";
import { Select } from "@/components/ui/Field";

const FIELD_CLASS =
  "w-full rounded-xl border border-line bg-surface px-3 py-2.5 text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary";

/**
 * Add a child, or skip.
 *
 * Skipping is a first-class option: a parent who wants to look around before
 * naming their child should not be blocked, and the same screen is reachable
 * again from the account (FR-074).
 *
 * ⚠️ IT CALLED `/parent/children`, A ROUTE SPEC 003 REMOVED — so this screen
 * was broken in production on BOTH doors: the list 404'd on mount and every
 * submission 404'd too, with «تعذّر إضافة الطالب» as the only sign of it. The
 * live endpoint is `GET|POST /family/relations`, which is also why the payload
 * keys below are `student_*` and carry a relation type and permissions: a
 * guardian link is not the flat "child" row the old route modelled.
 */
export function AddChildForm({ schoolYears }: { schoolYears: SchoolYearOption[] }) {
  const router = useRouter();

  const [children, setChildren] = useState<ChildLink[]>([]);
  const [form, setForm] = useState({ name: "", age: "", school_year_slug: "", code: "" });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    api
      .get<{ data: ChildLink[] }>("/family/relations")
      .then((res) => setChildren(res.data))
      .catch(() => setBanner("تعذّر تحميل قائمة الأبناء."));
  }, []);

  // With a code nothing else about the child is asked (see `submit`).
  const hasCode = form.code.trim() !== "";

  const set = (key: keyof typeof form, value: string) =>
    setForm((current) => ({ ...current, [key]: value }));

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setErrors({});
    setBanner("");
    setLoading(true);

    try {
      /*
       * ⚠️ THE BARE RESOURCE, NOT `{ data: … }`. `api.ts` re-wraps a bare ARRAY
       * into `{ data }` — and only an array. A single resource passes through
       * untouched, so the old `{ data: ChildLink }` type meant `created.data` was
       * `undefined` and the child pushed onto the signup list below was nothing at
       * all. TypeScript agreed with the lie because the type said so.
       */
      const code = form.code.trim();

      // The guardian who adds a child at signup gets the full set; the account
      // screen is where any of it is taken away again.
      const common = {
        relation_type: "parent",
        permissions: ["attendance", "payments", "schedule", "results", "academic_warnings"],
      };

      /*
       * ⛔ A CHILD WHO ALREADY HAS AN ACCOUNT IS NAMED BY THE CODE ALONE (owner
       * decision, 2026-09-24): no name, no age, no year. The server fills those
       * from the child's own account when they accept — never before, so a
       * pending request teaches the parent nothing about whoever the code
       * belongs to. Without a code the row is a name-only child, and only then
       * are the three fields asked. The code is omitted — never `""` — when
       * empty, since the server's `uuid` rule refuses an empty string.
       */
      const created = await api.post<ChildLink>(
        "/family/relations",
        code !== ""
          ? { student_uuid: code, ...common }
          : {
              student_name: form.name,
              age: form.age === "" ? null : Number(form.age),
              school_year_slug:
                form.school_year_slug === "" ? null : form.school_year_slug,
              ...common,
            },
      );

      setChildren((current) => [...current, created]);
      setForm({ name: "", age: "", school_year_slug: "", code: "" });
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
                <span className="font-medium text-ink">
                  {childLinkName(child.student_name, child.status)}
                </span>
                <span className="text-sm text-ink-muted">
                  {child.student_school_year_name ??
                    (child.student_age === null ? "—" : `${child.student_age} سنة`)}
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
          <label htmlFor="child-code" className="mb-1 block text-sm font-medium text-ink">
            رمز حساب الطالب — إن كان له حساب على المنصّة
          </label>
          <input
            id="child-code"
            value={form.code}
            onChange={(e) => set("code", e.target.value)}
            dir="ltr"
            aria-invalid={errors.student_uuid ? true : undefined}
            aria-describedby="child-code-hint"
            className={FIELD_CLASS}
          />
          <p id="child-code-hint" className="mt-1 text-sm text-ink-muted">
            يجده ابنك في صفحة «وليّ الأمر والأوصياء» من حسابه. يكفي الرمز وحده: يصله طلبك ليوافق
            عليه، ولا ترى شيئاً قبل موافقته.
          </p>
          {errors.student_uuid && (
            <p className="mt-1 text-sm text-danger-ink">{errors.student_uuid}</p>
          )}
        </div>

        {!hasCode && (
          <>
        <div>
          <label htmlFor="child-name" className="mb-1 block text-sm font-medium text-ink">
            اسم الطالب
          </label>
          <input
            id="child-name"
            value={form.name}
            onChange={(e) => set("name", e.target.value)}
            required
            aria-invalid={errors.student_name ? true : undefined}
            aria-describedby={errors.student_name ? "child-name-error" : undefined}
            className={FIELD_CLASS}
          />
          {errors.student_name && (
            <p id="child-name-error" className="mt-1 text-sm text-danger-ink">
              {errors.student_name}
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
            <label htmlFor="child-year" className="mb-1 block text-sm font-medium text-ink">
              الصف الدراسي
            </label>
            <Select
              id="child-year"
              value={form.school_year_slug}
              onChange={(e) => set("school_year_slug", e.target.value)}
              aria-invalid={errors.school_year_slug ? true : undefined}
              className={FIELD_CLASS}
            >
              <option value="">اختر الصف</option>
              {schoolYears.map((year) => (
                <option key={year.slug} value={year.slug}>
                  {year.name}
                </option>
              ))}
            </Select>
            {errors.school_year_slug && (
              <p className="mt-1 text-sm text-danger-ink">{errors.school_year_slug}</p>
            )}
          </div>
        </div>

          </>
        )}

        <Button type="submit" variant="accent" size="lg" fullWidth loading={loading} loadingLabel="جارٍ الإضافة…">
          إضافة الطفل
        </Button>
      </form>

      {/*
        ⚠️ THE PREFERENCES BLOCK THAT LIVED HERE CALLED A ROUTE THAT NO LONGER
        EXISTS (`/parent/notification-preferences`), and its own `if (!prefs)
        return null` hid both the section and the 404 — so a parent was never
        shown the choice at all. The account-wide screen is the one that works
        (`GET|PUT /notifications/preferences`), so this points at it rather than
        keeping a second, different editor for the same rows.
      */}
      <p className="text-sm text-ink-muted">
        تختار ما يصلك من تنبيهات عن أطفالك — الحضور والدرجات والمدفوعات — من{" "}
        <Link href="/settings/notifications" className="font-semibold text-primary-ink hover:underline">
          إعدادات التنبيهات
        </Link>
        {" "}في أيّ وقت.
      </p>

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
