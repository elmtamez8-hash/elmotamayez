"use client";

import { SignedInRedirect } from "@/components/auth/SignedInRedirect";
import { Suspense, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { Alert } from "@/components/ui/Alert";
import { AuthShell } from "@/components/auth/AuthShell";
import type { AuthSlide } from "@/components/auth/AuthSlides";
import { Button } from "@/components/ui/Button";
import { PasswordField, TextField } from "@/components/ui/Field";

/**
 * ⚠️ THE THREE SLIDES ARE THE THREE ROLES, AND EACH ONE CARRIES ITS OWN ROUTE.
 * This screen creates a STAFF account from a workspace invitation — nothing else
 * — so a visitor who landed here as a student, a parent or a teacher is on the
 * wrong page, and the panel is where that is said without an error. It is the
 * same fix as `/signup`, reached from the screen people actually arrive on.
 *
 * ⛔ INVITATION-ONLY SINCE 2026-09-24 (owner decision). `POST /auth/register`
 * refuses a request with no invitation, so without `?invitation=` this page
 * shows no form at all: a form the server will refuse is an error message
 * waiting to be typed. It used to be the academy founder's door too; spec 025 ·
 * FR-026 closed that, and what stayed open was a role-less account with no date
 * of birth and no guardian gate for anyone who typed the URL.
 */
const REGISTER_SLIDES: AuthSlide[] = [
  {
    title: "طالب؟",
    body: "احجز حصصك مع مدرّس تختاره بنفسك، فرديّة كانت أو جماعية.",
    href: "/signup/student",
    linkLabel: "سجّل كطالب",
  },
  {
    title: "وليّ أمر؟",
    body: "تابع حضور أبنائك وتقاريرهم ومدفوعاتهم من مكان واحد.",
    href: "/signup/parent",
    linkLabel: "سجّل كوليّ أمر",
  },
  {
    title: "مدرّس؟",
    body: "قدّم طلبك، وابنِ فصولك وموادّك بعد مراجعة أكاديمية.",
    href: "/signup/teacher",
    linkLabel: "قدّم طلبك",
  },
];

function RegisterForm() {
  const { register, login } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  const invitation = searchParams.get("invitation");
  const next = searchParams.get("next");

  // The invitation is bound to this address, so a prefilled one is locked: an
  // edited email is refused by the server («أُرسلت هذه الدعوة إلى …»).
  const invitedEmail = searchParams.get("email");

  const [form, setForm] = useState({
    first_name: "",
    last_name: "",
    email: invitedEmail ?? "",
    password: "",
    password_confirmation: "",
  });
  const [terms, setTerms] = useState(false);
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  const set = (key: keyof typeof form) => (value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setFields({});
    setLoading(true);

    try {
      await register({ ...form, invitation: invitation ?? undefined, terms_accepted: terms });

      // Sign the new account in and drop it back on the invitation to accept.
      await login(form.email, form.password);
      router.push(`/invitations/${invitation}`);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      // `invitation` has no field on this form, so its error goes on top.
      if (found.invitation) setError(found.invitation);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  if (!invitation) {
    return (
      <AuthShell
        subtitle="أنشئ حسابك"
        image="/marketplace/auth-register.webp"
        slides={REGISTER_SLIDES}
      >
        <div className="space-y-4 rounded-3xl border border-line bg-surface-raised p-8 shadow-sm">
          <Alert tone="info" title="هذه الصفحة لمن وصلته دعوة">
            يُنشأ الحساب هنا من رابط دعوةٍ وصلك على بريدك من أكاديمية أو مدرّس. إن كنت طالباً
            أو وليّ أمر أو مدرّساً فسجّل من صفحة التسجيل، ففيها ما يحتاجه حسابك.
          </Alert>

          <Button
            href={next === null ? "/signup" : `/signup?next=${encodeURIComponent(next)}`}
            fullWidth
          >
            اذهب إلى صفحة التسجيل
          </Button>

          <p className="text-center text-sm text-ink-muted">
            لديك حساب بالفعل؟{" "}
            <Link
              href="/login"
              className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              سجّل الدخول
            </Link>
          </p>
        </div>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      subtitle="أنشئ حسابك"
      image="/marketplace/auth-register.webp"
      slides={REGISTER_SLIDES}
    >
      <form
        onSubmit={submit}
        className="space-y-4 rounded-3xl border border-line bg-surface-raised p-8 shadow-sm"
      >
        {error && <Alert tone="danger" title={error} />}

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <TextField
            id="first_name"
            label="الاسم الأول"
            value={form.first_name}
            onChange={set("first_name")}
            error={fields.first_name}
            autoComplete="given-name"
            required
          />
          <TextField
            id="last_name"
            label="اسم العائلة"
            value={form.last_name}
            onChange={set("last_name")}
            error={fields.last_name}
            autoComplete="family-name"
          />
        </div>

        <TextField
          id="email"
          label="البريد الإلكتروني"
          type="email"
          value={form.email}
          onChange={set("email")}
          error={fields.email}
          placeholder="you@example.com"
          autoComplete="email"
          hint={invitedEmail ? "البريد الذي وصلته الدعوة." : undefined}
          disabled={invitedEmail !== null}
          required
        />

        <PasswordField
          id="password"
          label="كلمة المرور"
          value={form.password}
          onChange={set("password")}
          error={fields.password}
          hint="ثمانية أحرف على الأقل."
          autoComplete="new-password"
          minLength={8}
          required
        />

        <PasswordField
          id="password_confirmation"
          label="تأكيد كلمة المرور"
          value={form.password_confirmation}
          onChange={set("password_confirmation")}
          error={fields.password_confirmation}
          autoComplete="new-password"
          required
        />

        {/* Every other signup asks it; this one did not (assistant journey 2026-10-21). */}
        <div>
          <label className="flex items-start gap-3 text-sm text-ink">
            <input
              id="terms_accepted"
              type="checkbox"
              checked={terms}
              onChange={(e) => setTerms(e.target.checked)}
              aria-invalid={fields.terms_accepted ? true : undefined}
              aria-describedby={fields.terms_accepted ? "terms_accepted-error" : undefined}
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
          {fields.terms_accepted && (
            <p id="terms_accepted-error" className="mt-1 text-sm text-danger-ink">
              {fields.terms_accepted}
            </p>
          )}
        </div>

        <Button type="submit" fullWidth loading={loading} loadingLabel="جارٍ الإنشاء…">
          أنشئ الحساب
        </Button>

        <p className="text-center text-sm text-ink-muted">
          لديك حساب بالفعل؟{" "}
          <Link
            href={`/login?invitation=${invitation}`}
            className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            سجّل الدخول
          </Link>
        </p>
      </form>
    </AuthShell>
  );
}

// useSearchParams() reads the ?invitation= handoff, so the form needs a
// Suspense boundary to prerender.
export default function RegisterPage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center text-ink-muted">
          جارٍ التحميل…
        </div>
      }
    >
      <SignedInRedirect>
        <RegisterForm />
      </SignedInRedirect>
    </Suspense>
  );
}
