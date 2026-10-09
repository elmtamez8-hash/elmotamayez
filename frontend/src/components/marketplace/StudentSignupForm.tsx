"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { ApiError, auth, errorMessage, fieldErrors } from "@/lib/api";
import { errorCode } from "@/lib/errors";
import { Alert } from "@/components/ui/Alert";
import { COUNTRIES, DEFAULT_COUNTRY } from "@/lib/countries";
import { safeNext } from "@/lib/safe-next";
import { homePathFor, useAuth } from "@/lib/auth-context";
import type { SchoolYearOption, Taxonomy } from "@/lib/public-api";
import { PhoneInput, toE164 } from "@/components/ui/PhoneInput";
import { Button } from "@/components/ui/Button";
import { PasswordField, Select } from "@/components/ui/Field";
import { REFERRAL_CODE_MAX, sanitiseReferralCode } from "@/lib/referral-link";

const FIELD_CLASS =
  "w-full rounded-xl border border-line bg-surface px-3 py-2.5 text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary";

function Field({
  id,
  label,
  error,
  required = false,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  /** The same red star `ui/Field` and `PasswordField` draw, so every required field says so. */
  required?: boolean;
  children: React.ReactNode;
}) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-ink">
        {label}
        {required && (
          <span className="text-danger-ink" aria-hidden="true">
            {" *"}
          </span>
        )}
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

/** The fields drawn on step one — a server refusal of any of them returns there. */
const STEP_ONE = ["first_name", "last_name", "email", "phone", "password", "country"];

export function StudentSignupForm({
  schoolYears,
  regions,
  teacherUuid,
  next,
  referralCode = "",
}: {
  schoolYears: SchoolYearOption[];
  regions: Taxonomy[];
  teacherUuid?: string;
  /**
   * Where the visitor was going before they were asked to sign up (027 · FR-005).
   *
   * ⚠️ THIS PATH DOES SIGN THE ACCOUNT IN, so the intent is honoured here rather
   * than re-attached to another hop — and it goes through `safeNext()`, because
   * the value came off the address bar.
   */
  next?: string;
  /**
   * Spec 011 · FR-018 — the code from an invitation link (`?ref=`), already
   * sanitised by the page. It only PREFILLS: the visitor can edit or clear it,
   * and the server is the one that decides whether it exists.
   */
  referralCode?: string;
}) {
  const router = useRouter();
  const { adoptSession } = useAuth();

  const [form, setForm] = useState({
    first_name: "",
    last_name: "",
    email: "",
    password: "",
    // Spec 022 · FR-005 — the individual YEAR. The student's broad stage is
    // derived from it server-side; this form no longer sends one.
    // ⚠️ Empty, never the first option: a preselected «الروضة والتمهيدي» was a
    // grade nobody chose, sent as if they had (owner audit 2026-10-09). Unlike
    // the region below, a wrong grade is a wrong catalogue, not a statistic.
    school_year_slug: "",
    // Spec 011 · FR-042. Defaulted rather than left blank: the API requires it,
    // and a placeholder option is a 422 waiting for whoever does not notice a
    // select they were not asked to touch.
    region_slug: regions[0]?.slug ?? "",
    date_of_birth: "",
    guardian_contact: "",
    referral_code: referralCode,
    terms_accepted: false,
  });
  const [dial, setDial] = useState(DEFAULT_COUNTRY.dial);
  const [phone, setPhone] = useState("");
  const [byParent, setByParent] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [loading, setLoading] = useState(false);
  const [awaitingGuardian, setAwaitingGuardian] = useState(false);
  /*
   | Two steps (owner decision 2026-10-09): who you are, then what you study. One
   | request at the end — nothing is created between them, so «back» loses nothing.
   */
  const [step, setStep] = useState<1 | 2>(1);
  // Open from the start when an invitation link prefilled it.
  const [showReferral, setShowReferral] = useState(referralCode !== "");
  const formRef = useRef<HTMLFormElement>(null);

  // The country is the phone's: the dial picker lists the same `COUNTRIES`, so
  // asking for both was one question twice.
  const country = COUNTRIES.find((entry) => entry.dial === dial)?.code ?? DEFAULT_COUNTRY.code;

  /*
   | ⚠️ AFTER A REFUSAL, TAKE THE READER TO IT. The submit button is at the
   | bottom and the first field the server refused is usually near the top, so
   | the error appeared off screen and the press looked like it did nothing
   | (owner audit 2026-10-09). The first invalid control in DOM order is the one
   | they reach first; the banner when the refusal has no field.
   */
  useEffect(() => {
    const root = formRef.current;
    if (root === null) return;

    const target =
      root.querySelector<HTMLElement>('[aria-invalid="true"]') ??
      (banner !== "" ? root.querySelector<HTMLElement>('[role="alert"]') : null);

    if (target === null) return;

    target.scrollIntoView?.({ block: "center", behavior: "smooth" });
    if (target.matches("input, select, textarea")) target.focus({ preventScroll: true });
  }, [errors, banner]);

  // One key per mounted form, not per click: a double submit must reach the API
  // with the same key or the header buys nothing.
  const idempotencyKey = useMemo(
    () => (globalThis.crypto?.randomUUID?.() ?? String(Date.now())),
    [],
  );

  const set = (key: keyof typeof form, value: string | boolean) =>
    setForm((current) => ({ ...current, [key]: value }));

  /*
   * Under eighteen, decided from the date in this form — the same question the
   * API asks of the same payload. It only decides whether to DRAW the guardian
   * field: the server is the authority on whether the answer was needed, and a
   * blank date reads as «not a minor» so the date's own error surfaces first
   * rather than a second field appearing under it.
   */
  const isMinor = useMemo(() => {
    if (form.date_of_birth === "") return false;

    const born = new Date(form.date_of_birth);

    if (Number.isNaN(born.getTime())) return false;

    const eighteen = new Date(born.getFullYear() + 18, born.getMonth(), born.getDate());

    return eighteen > new Date();
  }, [form.date_of_birth]);

  /*
   | Step one is checked HERE before step two opens — only what the page can
   | know (filled, shaped like an email, eight characters). Whether the email is
   | free is the server's answer at the end, and a refusal there brings the
   | reader back to this step (`STEP_ONE` below).
   */
  const handleNext = () => {
    const missing: Record<string, string> = {};

    if (form.first_name.trim() === "") missing.first_name = "أدخل اسمك الأول.";
    if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) missing.email = "أدخل بريداً إلكترونياً صحيحاً.";
    if (phone.trim() === "") missing.phone = "أدخل رقم الجوال.";
    if (form.password.length < 8) missing.password = "كلمة المرور ثمانية أحرف على الأقل.";

    setErrors(missing);
    setBanner("");

    if (Object.keys(missing).length > 0) return;

    setStep(2);
    // On a phone «التالي» sits below the fold: start step two at its top.
    requestAnimationFrame(() => formRef.current?.scrollIntoView?.({ block: "start", behavior: "smooth" }));
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();

    // Enter on step one means «next», never «create the account».
    if (step === 1) {
      handleNext();

      return;
    }

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
      const { user, token, session_uuid } = await auth.registerStudent(
        {
          ...form,
          country,
          phone: toE164(dial, phone),
          registered_by_parent: byParent,
          // Sent only when there is one: an adult has no guardian to name, and
          // an empty string would fail the E.164 rule rather than be ignored.
          guardian_contact: form.guardian_contact === "" ? undefined : form.guardian_contact,
          // Same rule: an empty field means «nobody invited me», not a code to
          // look up — the API answers an unknown code 422.
          referral_code: form.referral_code === "" ? undefined : form.referral_code,
        },
        idempotencyKey,
      );

      // ⚠️ THROUGH THE PROVIDER, NEVER `setToken` BY HAND — the header reads
      // `user`, and a token written past it leaves the signed-OUT chrome on the
      // page this account was just created from.
      await adoptSession({ token, session_uuid, user });
      // Came from a teacher's booking CTA — return there rather than to a
      // generic landing page, so the intent that started the signup survives it.
      router.push(safeNext(next, teacherUuid ? `/teachers/${teacherUuid}` : homePathFor(user)));
    } catch (err: unknown) {
      /*
       * ⚠️ SPEC 013 — NOT A FAILURE. A minor's account IS created, and the
       * sign-in that follows refuses on purpose until a guardian consents. Shown
       * as an error it read «try again» — and trying again 422s on the email the
       * first attempt already registered.
       */
      if (err instanceof ApiError && errorCode(err.body) === "pending_guardian_consent") {
        setAwaitingGuardian(true);
        setLoading(false);

        return;
      }

      const fields = fieldErrors(err);
      // `country` has no field of its own now: it comes from the phone's code,
      // so its refusal is shown under the phone.
      setErrors(fields.country === undefined ? fields : { ...fields, phone: fields.phone ?? fields.country });
      // A taken email or a bad phone lives on step one: go back to it, where the
      // effect above focuses the field.
      if (STEP_ONE.some((key) => key in fields)) setStep(1);

      if (Object.keys(fields).length === 0) {
        setBanner(errorMessage(err, "تعذّر إنشاء الحساب، حاول مرة أخرى."));
      }
      setLoading(false);
    }
  };

  if (awaitingGuardian) {
    return (
      <Alert tone="info" title="أُنشئ حسابك، وهو بانتظار موافقة وليّ أمرك">
        يصل الطلب إلى وليّ أمرك في صفحة «وليّ الأمر والأوصياء» من حسابه على المنصّة. إن لم يكن له
        حساب بعد، فليُنشئه ويؤكّد رقم الجوّال الذي أدخلته هنا، فيصله الطلب تلقائياً. تستطيع تسجيل
        الدخول فور موافقته.
      </Alert>
    );
  }

  return (
    <form ref={formRef} onSubmit={handleSubmit} noValidate className="scroll-mt-28 space-y-5">
      {/* Where the reader is: two dots and a sentence, read aloud as one line. */}
      <div className="flex items-center gap-3" aria-live="polite">
        <div aria-hidden="true" className="flex gap-1.5">
          {[1, 2].map((dot) => (
            <span key={dot} className={`h-1.5 w-8 rounded-full ${dot <= step ? "bg-primary" : "bg-line"}`} />
          ))}
        </div>
        <p className="text-sm font-bold text-ink-muted">
          {step === 1 ? "الخطوة ١ من ٢ · بياناتك" : "الخطوة ٢ من ٢ · دراستك"}
        </p>
      </div>

      {banner && (
        <p role="alert" className="rounded-xl bg-danger/10 p-3 text-sm text-danger-ink">
          {banner}
        </p>
      )}

      {step === 1 && (
        <>
          <div className="grid gap-5 sm:grid-cols-2">
            <Field id="first_name" label="الاسم الأول" error={errors.first_name} required>
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

          <Field id="email" label="البريد الإلكتروني" error={errors.email} required>
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

          {/* The dial code also names the country (`country` above). */}
          <PhoneInput
            id="phone"
            dial={dial}
            number={phone}
            onDialChange={setDial}
            onNumberChange={setPhone}
            error={errors.phone}
          />

          {/* Once, with the eye toggle `PasswordField` carries — no second copy. */}
          <PasswordField
            id="password"
            label="كلمة المرور"
            error={errors.password}
            value={form.password}
            onChange={(value) => set("password", value)}
            autoComplete="new-password"
            required
            minLength={8}
          />

          <Button type="submit" variant="accent" size="lg" fullWidth>
            التالي
          </Button>
        </>
      )}

      {step === 2 && (
        <>
          <div className="grid gap-5 sm:grid-cols-2">
            <Field id="school_year_slug" label="الصف الدراسي" error={errors.school_year_slug} required>
              <Select
                id="school_year_slug"
                value={form.school_year_slug}
                onChange={(e) => set("school_year_slug", e.target.value)}
                required
                aria-invalid={errors.school_year_slug ? true : undefined}
                aria-describedby={errors.school_year_slug ? "school_year_slug-error" : undefined}
                className={FIELD_CLASS}
              >
                <option value="" disabled>
                  اختر الصف
                </option>
                {schoolYears.map((year) => (
                  <option key={year.slug} value={year.slug}>
                    {year.name}
                  </option>
                ))}
              </Select>
            </Field>

            {/* Spec 011 · FR-042 — mandatory at registration, and the only source of
                the platform's regional picture. */}
            <Field id="region_slug" label="المنطقة" error={errors.region_slug} required>
              <Select
                id="region_slug"
                value={form.region_slug}
                onChange={(e) => set("region_slug", e.target.value)}
                required
                className={FIELD_CLASS}
              >
                {regions.map((region) => (
                  <option key={region.slug} value={region.slug}>
                    {region.name}
                  </option>
                ))}
              </Select>
            </Field>
          </div>

          {/* Spec 013 · FR-009 — the age question, asked once at the door. */}
          <Field id="date_of_birth" label="تاريخ الميلاد" error={errors.date_of_birth} required>
            <input
              id="date_of_birth"
              type="date"
              dir="ltr"
              value={form.date_of_birth}
              onChange={(e) => set("date_of_birth", e.target.value)}
              required
              aria-invalid={errors.date_of_birth ? true : undefined}
              aria-describedby={errors.date_of_birth ? "date_of_birth-error" : undefined}
              className={FIELD_CLASS}
            />
          </Field>

          {isMinor && (
            <Field
              id="guardian_contact"
              label="رقم جوّال وليّ الأمر"
              error={errors.guardian_contact}
              required
            >
              <input
                id="guardian_contact"
                type="tel"
                dir="ltr"
                value={form.guardian_contact}
                onChange={(e) => set("guardian_contact", e.target.value)}
                placeholder="+97455512345"
                required
                aria-invalid={errors.guardian_contact ? true : undefined}
                aria-describedby={errors.guardian_contact ? "guardian_contact-hint guardian_contact-error" : "guardian_contact-hint"}
                className={FIELD_CLASS}
              />
              <p id="guardian_contact-hint" className="mt-1 text-sm text-ink-muted">
                لأنّك دون الثامنة عشرة، يُفعَّل حسابك بعد موافقة وليّ أمرك.
              </p>
            </Field>
          )}

          {/* Spec 011 · FR-018 — optional, so behind a link unless an invitation
              link prefilled it or the server refused it. An unknown code comes
              back as a 422 under this field, where it can be fixed or cleared. */}
          {showReferral || errors.referral_code ? (
            <Field id="referral_code" label="كود الدعوة (اختياري)" error={errors.referral_code}>
              <input
                id="referral_code"
                dir="ltr"
                value={form.referral_code}
                onChange={(e) => set("referral_code", sanitiseReferralCode(e.target.value))}
                maxLength={REFERRAL_CODE_MAX}
                autoComplete="off"
                autoCapitalize="characters"
                spellCheck={false}
                aria-invalid={errors.referral_code ? true : undefined}
                aria-describedby={errors.referral_code ? "referral_code-hint referral_code-error" : "referral_code-hint"}
                className={FIELD_CLASS}
              />
              <p id="referral_code-hint" className="mt-1 text-sm text-ink-muted">
                اكتب الكود الذي أرسله لك صديقك.
              </p>
            </Field>
          ) : (
            <button
              type="button"
              onClick={() => setShowReferral(true)}
              className="text-sm font-bold text-primary-ink underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              عندك كود دعوة من صديق؟
            </button>
          )}

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

          <div className="flex flex-col-reverse gap-3 sm:flex-row">
            <Button type="button" variant="secondary" size="lg" onClick={() => setStep(1)} disabled={loading}>
              رجوع
            </Button>
            <div className="flex-1">
              <Button type="submit" variant="accent" size="lg" fullWidth loading={loading} loadingLabel="جارٍ إنشاء الحساب…">
                إنشاء حساب طالب
              </Button>
            </div>
          </div>
        </>
      )}

      <p className="text-center text-sm text-ink-muted">
        لديك حساب؟{" "}
        <Link href="/login" className="text-primary-ink underline">
          سجّل الدخول
        </Link>
      </p>
    </form>
  );
}
