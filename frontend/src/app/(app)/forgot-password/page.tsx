"use client";

import Link from "next/link";
import { Suspense, useState } from "react";

import { SignedInRedirect } from "@/components/auth/SignedInRedirect";
import { AuthShell } from "@/components/auth/AuthShell";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";
import { auth, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";

/**
 * «نسيت كلمة المرور» — asks for an address and says the same thing whatever it
 * finds. The server answers identically for an unknown address, so this screen
 * never tells a visitor whether somebody has an account here.
 */
function ForgotPasswordForm() {
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState<string | null>(null);
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setFields({});
    setLoading(true);

    try {
      setSent((await auth.forgotPassword(email)).message);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthShell subtitle="استعادة كلمة المرور">
      <form
        onSubmit={submit}
        className="space-y-4 rounded-2xl border border-line bg-surface-raised p-8"
      >
        {sent !== null ? (
          <Alert tone="success" title={sent}>
            افتح الرسالة واضغط الرابط الذي فيها. إن لم تجدها فانظر في مجلّد الرسائل غير المرغوبة.
          </Alert>
        ) : (
          <>
            {error && <Alert tone="danger" title={error} />}

            <p className="text-sm text-ink-muted">
              اكتب بريدك الإلكتروني وسنرسل لك رابطاً تختار به كلمة مرور جديدة.
            </p>

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

            <Button type="submit" fullWidth loading={loading} loadingLabel="جارٍ الإرسال…">
              أرسل الرابط
            </Button>
          </>
        )}

        <p className="text-center text-sm text-ink-muted">
          <Link
            href="/login"
            className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            العودة لتسجيل الدخول
          </Link>
        </p>
      </form>
    </AuthShell>
  );
}

// `SignedInRedirect` reads `?next=`, so the page needs a Suspense boundary to prerender.
export default function ForgotPasswordPage() {
  return (
    <Suspense fallback={null}>
      <SignedInRedirect>
        <ForgotPasswordForm />
      </SignedInRedirect>
    </Suspense>
  );
}
