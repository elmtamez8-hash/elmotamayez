"use client";

import { Suspense, useState } from "react";
import { homePathFor, useAuth } from "@/lib/auth-context";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { sessionEndedLabel } from "@/lib/labels";
import { Alert } from "@/components/ui/Alert";
import { AuthShell } from "@/components/auth/AuthShell";
import type { AuthSlide } from "@/components/auth/AuthSlides";
import { Button } from "@/components/ui/Button";
import { PasswordField, TextField } from "@/components/ui/Field";
import type { User } from "@/lib/types";

/**
 * Sign-in is read by someone who already belongs here, so the panel does not sell
 * the product — it says what is waiting on the other side of the password. The
 * three cards are the three things an account holder came back FOR.
 */
const LOGIN_SLIDES: AuthSlide[] = [
  {
    title: "حصصك في انتظارك",
    body: "جدولك، وحصصك المباشرة، وتسجيلاتها — كلّها حيث تركتها.",
  },
  {
    title: "تابع تقدّمك",
    body: "درجاتك ودفتر أخطائك ولوحة الصدارة، محدَّثة منذ آخر مرّة درست فيها.",
  },
  {
    title: "لم تنضمّ بعد؟",
    body: "اختر صفتك وابدأ مع مدرّس يمرّ بمراجعة أكاديمية قبل انضمامه.",
    href: "/signup",
    linkLabel: "أنشئ حساباً",
  },
];

function LoginForm() {
  const { login } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  // Arrived from an invitation link: go back to it so the user can accept.
  const invitation = searchParams.get("invitation");
  // Arrived here because a session ended elsewhere — say which, so an eviction
  // by someone else using the account does not read as a bug in the app.
  const ended = sessionEndedLabel(searchParams.get("ended"));

  const [email, setEmail] = useState(searchParams.get("email") ?? "");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);
  // Set when the password was right but the account has a second factor. The
  // form below is replaced rather than extended: there is no token yet, and
  // leaving the password fields on screen invites re-submitting them.
  const [challenge, setChallenge] = useState<string | null>(null);

  const done = (user: User) =>
    router.push(invitation ? `/invitations/${invitation}` : homePathFor(user));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setFields({});
    setLoading(true);

    try {
      const outcome = await login(email, password);

      if ("challenge" in outcome) setChallenge(outcome.challenge);
      else done(outcome.user);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  if (challenge !== null) {
    return <TwoFactorChallenge challenge={challenge} onSignedIn={done} />;
  }

  return (
    <AuthShell
      subtitle="سجّل الدخول إلى حسابك"
      image="/marketplace/auth-login.webp"
      slides={LOGIN_SLIDES}
    >
      <form
        onSubmit={submit}
        className="space-y-4 rounded-2xl border border-line bg-surface-raised p-8"
      >
        {ended !== null && error === "" && (
          <Alert tone="warning" title="أُنهيت جلستك">
            {ended}
          </Alert>
        )}

        {error && <Alert tone="danger" title={error} />}

        <TextField
          id="email"
          label="البريد الإلكتروني"
          type="email"
          value={email}
          onChange={setEmail}
          error={fields.email}
          placeholder="you@example.com"
          autoComplete="email"
          required
        />

        <PasswordField
          id="password"
          label="كلمة المرور"
          value={password}
          onChange={setPassword}
          error={fields.password}
          autoComplete="current-password"
          required
        />

        <Button type="submit" fullWidth loading={loading} loadingLabel="جارٍ الدخول…">
          تسجيل الدخول
        </Button>

        <p className="text-center text-sm text-ink-muted">
          لا تملك حساباً؟{" "}
          {/*
            * `/signup`, not `/register` — the chooser, not the role-less form.
            * `/register` asks for none of what a student's account needs (a date
            * of birth above all, which `RegisterStudent` turns into the guardian
            * gate), so an ordinary visitor sent there registers with no year, no
            * region and no gate, silently. It stays the destination when an
            * invitation is in hand: that IS the account it creates.
            */}
          <Link
            href={invitation ? `/register?invitation=${invitation}` : "/signup"}
            className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            أنشئ حساباً
          </Link>
        </p>
      </form>
    </AuthShell>
  );
}

/**
 * The second half of signing in.
 *
 * Recovery codes are a deliberate second path, not a hidden one: someone whose
 * phone is lost or reset needs the way back to be visible on the screen that is
 * blocking them, not buried in a help page they cannot reach signed out.
 */
function TwoFactorChallenge({
  challenge,
  onSignedIn,
}: {
  challenge: string;
  onSignedIn: (user: User) => void;
}) {
  const { completeTwoFactor } = useAuth();
  const [code, setCode] = useState("");
  const [useRecovery, setUseRecovery] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      onSignedIn(
        await completeTwoFactor(
          challenge,
          useRecovery ? { recovery_code: code } : { code },
        ),
      );
    } catch (err: unknown) {
      setError(userMessage(err));
      setCode("");
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthShell
    subtitle={
      useRecovery
        ? "أدخل أحد رموز الاسترداد التي حفظتها"
        : "أدخل الرمز من تطبيق المصادقة"
    }
  >
      <form
        onSubmit={submit}
        className="space-y-4 rounded-2xl border border-line bg-surface-raised p-8"
      >
        {error && <Alert tone="danger" title={error} />}

        <TextField
          id="code"
          label={useRecovery ? "رمز الاسترداد" : "الرمز"}
          value={code}
          onChange={setCode}
          autoComplete="one-time-code"
          maxLength={useRecovery ? 32 : 6}
          required
        />

        <Button type="submit" fullWidth loading={loading} loadingLabel="جارٍ التحقق…">
          تأكيد
        </Button>

        <button
          type="button"
          onClick={() => {
            setUseRecovery((current) => !current);
            setCode("");
            setError("");
          }}
          className="w-full rounded text-center text-sm text-ink-muted underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          {useRecovery ? "العودة إلى رمز التطبيق" : "لا يمكنك الوصول إلى تطبيق المصادقة؟"}
        </button>
      </form>
    </AuthShell>
  );
}

// useSearchParams() reads the ?invitation= handoff, so the form needs a
// Suspense boundary to prerender.
export default function LoginPage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center text-ink-muted">
          جارٍ التحميل…
        </div>
      }
    >
      <LoginForm />
    </Suspense>
  );
}
