"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { ShieldIcon } from "@/components/icons";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";

/**
 * «أرسل رابطًا جديدًا» — the way out of an expired or broken confirmation link.
 *
 * ⚠️ IT LIVES HERE, NOT ON `/login`, BECAUSE THE ROUTE NEEDS A SESSION.
 * `POST /auth/email/verification-notification` is behind `auth:sanctum` — the
 * server takes the address from the signed-in account rather than from a form,
 * which is exactly what keeps it from mailing a stranger's inbox on request. The
 * login page's «رابط التأكيد غير صالح» alert sends the reader here after they
 * sign in; before this card existed that alert was a dead end.
 *
 * Shown only while the account's address is unconfirmed: `email_verified_at`
 * is on `/auth/me`, and a confirmed account has nothing to ask for.
 */
export function EmailVerificationSection() {
  const { user } = useAuth();
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState("");

  if (user === null || user.email_verified_at !== null) return null;

  const send = async () => {
    setError("");
    setSending(true);

    try {
      await api.post("/auth/email/verification-notification");
      setSent(true);
    } catch (err: unknown) {
      // A 429 from the limiter reads as «try again later», never as a raw code.
      setError(userMessage(err));
    } finally {
      setSending(false);
    }
  };

  return (
    <Card as="section">
      <SectionHeading
        id="email-verification"
        Icon={ShieldIcon}
        title="تأكيد البريد الإلكتروني"
        description={
          <>
            لم يُؤكَّد بريدك <bdi dir="ltr">{user.email}</bdi> بعد. إن انتهت مدّة رابط
            التأكيد أو لم يصلك، اطلب رابطاً جديداً.
          </>
        }
      />

      {error !== "" && (
        <div className="mt-4">
          <Alert tone="danger" title={error} />
        </div>
      )}

      {sent ? (
        <div className="mt-4">
          <Alert tone="success" title="أرسلنا رابطاً جديداً إلى بريدك">
            افتح الرسالة واضغط الرابط خلال مدّة صلاحيته.
          </Alert>
        </div>
      ) : (
        <div className="mt-4">
          <Button onClick={send} loading={sending}>
            أرسل رابطًا جديدًا
          </Button>
        </div>
      )}
    </Card>
  );
}
