"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { auth, setToken, errorMessage, fieldErrors } from "@/lib/api";
import { COUNTRIES, DEFAULT_COUNTRY } from "@/lib/countries";
import { homePathFor } from "@/lib/auth-context";
import type { Taxonomy } from "@/lib/public-api";
import { PhoneInput, toE164 } from "@/components/ui/PhoneInput";
import { Button } from "@/components/ui/Button";

const FIELD_CLASS =
  "w-full rounded-xl border border-line bg-surface px-3 py-2.5 text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary";

function Field({
  id,
  label,
  error,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-ink">
        {label}
      </label>
      {children}
      {error && (
        <p id={`${id}-error`} className="mt-1 text-sm text-danger-ink">
          {error}
        </p>
      )}
    </div>
  );
}

export function StudentSignupForm({
  gradeLevels,
  teacherUuid,
}: {
  gradeLevels: Taxonomy[];
  teacherUuid?: string;
}) {
  const router = useRouter();

  const [form, setForm] = useState({
    first_name: "",
    last_name: "",
    email: "",
    password: "",
    password_confirmation: "",
    country: DEFAULT_COUNTRY.code,
    grade_level_slug: gradeLevels[0]?.slug ?? "",
    terms_accepted: false,
  });
  const [dial, setDial] = useState(DEFAULT_COUNTRY.dial);
  const [phone, setPhone] = useState("");
  const [byParent, setByParent] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [loading, setLoading] = useState(false);

  // One key per mounted form, not per click: a double submit must reach the API
  // with the same key or the header buys nothing.
  const idempotencyKey = useMemo(
    () => (globalThis.crypto?.randomUUID?.() ?? String(Date.now())),
    [],
  );

  const set = (key: keyof typeof form, value: string | boolean) =>
    setForm((current) => ({ ...current, [key]: value }));

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setErrors({});
    setBanner("");

    // Checked here as well as on the server (FR-065). The server is still the
    // authority; this only spares the user a round trip to be told something the
    // page already knows.
    if (!form.terms_accepted) {
      setErrors({ terms_accepted: "يجب الموافقة على الشروط والأحكام." });

      return;
    }

    setLoading(true);

    try {
      const { user, token } = await auth.registerStudent(
        {
          ...form,
          phone: toE164(dial, phone),
          registered_by_parent: byParent,
        },
        idempotencyKey,
      );

      setToken(token);
      // Came from a teacher's booking CTA — return there rather than to a
      // generic landing page, so the intent that started the signup survives it.
      router.push(teacherUuid ? `/teachers/${teacherUuid}` : homePathFor(user));
    } catch (err: unknown) {
      const fields = fieldErrors(err);
      setErrors(fields);

      if (Object.keys(fields).length === 0) {
        setBanner(errorMessage(err, "تعذّر إنشاء الحساب، حاول مرة أخرى."));
      }
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} noValidate className="space-y-5">
      {banner && (
        <p role="alert" className="rounded-xl bg-danger/10 p-3 text-sm text-danger-ink">
          {banner}
        </p>
      )}

      <div className="grid gap-5 sm:grid-cols-2">
        <Field id="first_name" label="الاسم الأول" error={errors.first_name}>
          <input
            id="first_name"
            value={form.first_name}
            onChange={(e) => set("first_name", e.target.value)}
            autoComplete="given-name"
            required
            aria-invalid={errors.first_name ? true : undefined}
            aria-describedby={errors.first_name ? "first_name-error" : undefined}
            className={FIELD_CLASS}
          />
        </Field>

        <Field id="last_name" label="اسم العائلة" error={errors.last_name}>
          <input
            id="last_name"
            value={form.last_name}
            onChange={(e) => set("last_name", e.target.value)}
            autoComplete="family-name"
            className={FIELD_CLASS}
          />
        </Field>
      </div>

      <Field id="email" label="البريد الإلكتروني" error={errors.email}>
        <input
          id="email"
          type="email"
          dir="ltr"
          value={form.email}
          onChange={(e) => set("email", e.target.value)}
          autoComplete="email"
          required
          aria-invalid={errors.email ? true : undefined}
          aria-describedby={errors.email ? "email-error" : undefined}
          className={FIELD_CLASS}
        />
      </Field>

      <PhoneInput
        id="phone"
        dial={dial}
        number={phone}
        onDialChange={setDial}
        onNumberChange={setPhone}
        error={errors.phone}
      />

      <div className="grid gap-5 sm:grid-cols-2">
        <Field id="country" label="الدولة" error={errors.country}>
          <select
            id="country"
            value={form.country}
            onChange={(e) => set("country", e.target.value)}
            className={FIELD_CLASS}
          >
            {COUNTRIES.map((country) => (
              <option key={country.code} value={country.code}>
                {country.name_ar}
              </option>
            ))}
          </select>
        </Field>

        <Field id="grade_level_slug" label="المرحلة الدراسية" error={errors.grade_level_slug}>
          <select
            id="grade_level_slug"
            value={form.grade_level_slug}
            onChange={(e) => set("grade_level_slug", e.target.value)}
            required
            className={FIELD_CLASS}
          >
            {gradeLevels.map((level) => (
              <option key={level.slug} value={level.slug}>
                {level.name_ar}
              </option>
            ))}
          </select>
        </Field>
      </div>

      <div className="grid gap-5 sm:grid-cols-2">
        <Field id="password" label="كلمة المرور" error={errors.password}>
          <input
            id="password"
            type="password"
            value={form.password}
            onChange={(e) => set("password", e.target.value)}
            autoComplete="new-password"
            required
            minLength={8}
            aria-invalid={errors.password ? true : undefined}
            aria-describedby={errors.password ? "password-error" : undefined}
            className={FIELD_CLASS}
          />
        </Field>

        <Field id="password_confirmation" label="تأكيد كلمة المرور">
          <input
            id="password_confirmation"
            type="password"
            value={form.password_confirmation}
            onChange={(e) => set("password_confirmation", e.target.value)}
            autoComplete="new-password"
            required
            className={FIELD_CLASS}
          />
        </Field>
      </div>

      {/* FR-064 */}
      <div className="rounded-xl border border-line p-4">
        <label className="flex items-center justify-between gap-3">
          <span className="text-sm font-medium text-ink">التسجيل بواسطة وليّ الأمر</span>
          <input
            type="checkbox"
            role="switch"
            checked={byParent}
            onChange={(e) => setByParent(e.target.checked)}
            className="h-5 w-9 accent-primary"
          />
        </label>

        {byParent && (
          <p className="mt-3 text-sm text-ink-muted">
            الحساب سيُنشأ باسم الطالب، ويمكن لوليّ الأمر ربط حسابه به لاحقاً
            لمتابعة التقارير والحصص.
          </p>
        )}
      </div>

      {/* FR-065: starts unchecked, and the API rejects the request if it stays that way. */}
      <div>
        <label className="flex items-start gap-3 text-sm text-ink">
          <input
            id="terms_accepted"
            type="checkbox"
            checked={form.terms_accepted}
            onChange={(e) => set("terms_accepted", e.target.checked)}
            aria-invalid={errors.terms_accepted ? true : undefined}
            aria-describedby={errors.terms_accepted ? "terms_accepted-error" : undefined}
            className="mt-0.5 h-4 w-4 accent-primary"
          />
          <span>
            أوافق على{" "}
            <Link href="/terms" className="text-primary-ink underline">
              الشروط والأحكام
            </Link>{" "}
            و
            <Link href="/privacy" className="text-primary-ink underline">
              سياسة الخصوصية
            </Link>
            .
          </span>
        </label>
        {errors.terms_accepted && (
          <p id="terms_accepted-error" className="mt-1 text-sm text-danger-ink">
            {errors.terms_accepted}
          </p>
        )}
      </div>

      <Button type="submit" variant="accent" size="lg" fullWidth loading={loading} loadingLabel="جارٍ إنشاء الحساب…">
        إنشاء حساب طالب
      </Button>

      <p className="text-center text-sm text-ink-muted">
        لديك حساب؟{" "}
        <Link href="/login" className="text-primary-ink underline">
          سجّل الدخول
        </Link>
      </p>
    </form>
  );
}
