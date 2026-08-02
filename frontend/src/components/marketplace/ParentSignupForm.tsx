"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { auth, setToken, errorMessage, fieldErrors } from "@/lib/api";
import { COUNTRIES, DEFAULT_COUNTRY } from "@/lib/countries";
import { PhoneInput, toE164 } from "./PhoneInput";
import { SubmitButton } from "./SubmitButton";

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

/**
 * Parent signup. No grade level and no "registered by" toggle — the child's
 * details belong to the child, and asking for them here would collect them twice
 * (FR-073). The next screen adds children.
 */
export function ParentSignupForm() {
  const router = useRouter();

  const [form, setForm] = useState({
    first_name: "",
    last_name: "",
    email: "",
    password: "",
    password_confirmation: "",
    country: DEFAULT_COUNTRY.code,
    terms_accepted: false,
  });
  const [dial, setDial] = useState(DEFAULT_COUNTRY.dial);
  const [phone, setPhone] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [loading, setLoading] = useState(false);

  const idempotencyKey = useMemo(
    () => globalThis.crypto?.randomUUID?.() ?? String(Date.now()),
    [],
  );

  const set = (key: keyof typeof form, value: string | boolean) =>
    setForm((current) => ({ ...current, [key]: value }));

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setErrors({});
    setBanner("");

    // Checked here as well as on the server (FR-065); the server stays the
    // authority, this only spares a round trip.
    if (!form.terms_accepted) {
      setErrors({ terms_accepted: "يجب الموافقة على الشروط والأحكام." });

      return;
    }

    setLoading(true);

    try {
      const { token } = await auth.registerParent(
        { ...form, phone: toE164(dial, phone) },
        idempotencyKey,
      );

      setToken(token);
      router.push("/signup/parent/children");
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
            <Link href="/terms" className="text-primary underline">
              الشروط والأحكام
            </Link>{" "}
            و
            <Link href="/privacy" className="text-primary underline">
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

      <SubmitButton loading={loading} loadingLabel="جارٍ إنشاء الحساب…">
        إنشاء حساب وليّ أمر
      </SubmitButton>

      <p className="text-center text-sm text-ink-muted">
        لديك حساب؟{" "}
        <Link href="/login" className="text-primary underline">
          سجّل الدخول
        </Link>
      </p>
    </form>
  );
}
