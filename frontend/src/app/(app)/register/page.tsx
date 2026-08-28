"use client";

import { Suspense, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import { fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { PLATFORM_NAME } from "@/lib/platform";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";

function RegisterForm() {
  const { register, login } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  const invitation = searchParams.get("invitation");

  const [form, setForm] = useState({
    first_name: "",
    last_name: "",
    // The invitation is bound to this address, so don't let it drift.
    email: searchParams.get("email") ?? "",
    password: "",
    password_confirmation: "",
  });
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
      await register({ ...form, invitation: invitation ?? undefined });

      if (invitation) {
        // Sign the new account in and drop it back on the invitation to accept.
        await login(form.email, form.password);
        router.push(`/invitations/${invitation}`);
        return;
      }

      router.push("/login");
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <main id="main" className="flex min-h-screen items-center justify-center px-4 py-10">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <h1 className="text-3xl font-bold text-primary-ink">{PLATFORM_NAME}</h1>
          <p className="mt-2 text-ink-muted">أنشئ حسابك</p>
        </div>

        <form
          onSubmit={submit}
          className="space-y-4 rounded-2xl border border-line bg-surface-raised p-8"
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
            required
          />

          <TextField
            id="password"
            label="كلمة المرور"
            type="password"
            value={form.password}
            onChange={set("password")}
            error={fields.password}
            hint="ثمانية أحرف على الأقل."
            autoComplete="new-password"
            minLength={8}
            required
          />

          <TextField
            id="password_confirmation"
            label="تأكيد كلمة المرور"
            type="password"
            value={form.password_confirmation}
            onChange={set("password_confirmation")}
            error={fields.password_confirmation}
            autoComplete="new-password"
            required
          />

          <Button type="submit" fullWidth loading={loading} loadingLabel="جارٍ الإنشاء…">
            أنشئ الحساب
          </Button>

          {!invitation && (
            /*
             * ⚠️ This door creates a role-less account: it is the academy
             * founder's path (register, then create a workspace), and it asks
             * for none of what a student's account needs — a date of birth
             * above all, which `RegisterStudent` turns into the guardian gate.
             * Sending the other three roles to their own signup is what keeps
             * that gate on one implementation.
             */
            <p className="text-center text-sm text-ink-muted">
              تسجّل بصفة{" "}
              <Link href="/signup/student" className="rounded text-primary-ink underline underline-offset-4">
                طالب
              </Link>{" "}
              أو{" "}
              <Link href="/signup/parent" className="rounded text-primary-ink underline underline-offset-4">
                وليّ أمر
              </Link>{" "}
              أو{" "}
              <Link href="/signup/teacher" className="rounded text-primary-ink underline underline-offset-4">
                مدرّس
              </Link>
              ؟ لكلٍّ صفحته.
            </p>
          )}

          <p className="text-center text-sm text-ink-muted">
            لديك حساب بالفعل؟{" "}
            <Link
              href={invitation ? `/login?invitation=${invitation}` : "/login"}
              className="rounded text-primary-ink underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              سجّل الدخول
            </Link>
          </p>
        </form>
      </div>
    </main>
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
      <RegisterForm />
    </Suspense>
  );
}
