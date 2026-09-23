"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { AuthShell } from "@/components/auth/AuthShell";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { PasswordField } from "@/components/ui/Field";
import { auth, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";

/**
 * The page the reset mail links to (`IdentityServiceProvider` builds the URL).
 *
 * The token and the address come from the link, never from a field: a form that
 * let the reader edit the address would only produce the server's «invalid
 * token» for a typo nobody can see.
 */
function ResetPasswordForm() {
  const router = useRouter();
  const params = useSearchParams();
  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";

  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  if (token === "" || email === "") {
    return (
      <Alert tone="danger" title="الرابط غير مكتمل">
        افتح الرابط كما وصلك في الرسالة، أو{" "}
        <Link href="/forgot-password" className="underline underline-offset-4">
          اطلب رابطاً جديداً
        </Link>
        .
      </Alert>
    );
  }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setFields({});
    setLoading(true);

    try {
      await auth.resetPassword({ email, token, password, password_confirmation: confirmation });
      router.push(`/login?email=${encodeURIComponent(email)}`);
    } catch (err: unknown) {
      const found = fieldErrors(err);
      // The broker reports a bad or expired token against `email`, a field this
      // form does not show — so it goes to the banner instead.
      if (found.email) setError(found.email);
      else if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-4 rounded-2xl border border-line bg-surface-raised p-8">
      {error && (
        <Alert tone="danger" title={error}>
          <Link href="/forgot-password" className="underline underline-offset-4">
            اطلب رابطاً جديداً
          </Link>
        </Alert>
      )}

      <p className="text-sm text-ink-muted">
        كلمة مرور جديدة لحساب <bdi dir="ltr">{email}</bdi>
      </p>

      <PasswordField
        id="password"
        label="كلمة المرور الجديدة"
        value={password}
        onChange={setPassword}
        error={fields.password}
        autoComplete="new-password"
        required
      />

      <PasswordField
        id="password_confirmation"
        label="تأكيد كلمة المرور"
        value={confirmation}
        onChange={setConfirmation}
        error={fields.password_confirmation}
        autoComplete="new-password"
        required
      />

      <Button type="submit" fullWidth loading={loading} loadingLabel="جارٍ الحفظ…">
        احفظ كلمة المرور
      </Button>
    </form>
  );
}

export default function ResetPasswordPage() {
  return (
    <AuthShell subtitle="اختر كلمة مرور جديدة">
      <Suspense>
        <ResetPasswordForm />
      </Suspense>
    </AuthShell>
  );
}
