"use client";

import { Suspense, useState } from "react";
import { homePathFor, useAuth } from "@/lib/auth-context";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { PLATFORM_NAME } from "@/lib/platform";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";

function LoginForm() {
  const { login } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  // Arrived from an invitation link: go back to it so the user can accept.
  const invitation = searchParams.get("invitation");

  const [email, setEmail] = useState(searchParams.get("email") ?? "");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setFields({});
    setLoading(true);

    try {
      const user = await login(email, password);
      router.push(invitation ? `/invitations/${invitation}` : homePathFor(user));
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <main id="main" className="flex min-h-screen items-center justify-center px-4">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <h1 className="text-3xl font-bold text-primary-ink">{PLATFORM_NAME}</h1>
          <p className="mt-2 text-ink-muted">سجّل الدخول إلى حسابك</p>
        </div>

        <form
          onSubmit={submit}
          className="space-y-4 rounded-2xl border border-line bg-surface-raised p-8"
        >
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

          <TextField
            id="password"
            label="كلمة المرور"
            type="password"
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
            <Link
              href={invitation ? `/register?invitation=${invitation}` : "/register"}
              className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              أنشئ حساباً
            </Link>
          </p>
        </form>
      </div>
    </main>
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
