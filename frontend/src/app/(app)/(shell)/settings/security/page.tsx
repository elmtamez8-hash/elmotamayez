"use client";

import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { PasswordField, TextField } from "@/components/ui/Field";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import { sessions, type AuthSession } from "@/lib/sessions";
import { twoFactor, type TwoFactorState } from "@/lib/two-factor";

/**
 * Where a student sees which devices are signed in, and ends any of them.
 *
 * This is the answer to the account that was shared and then regretted: the
 * device limit stops the sharing continuing, but only this screen lets the owner
 * cut a session they did not start, without changing their password and without
 * asking anyone.
 */
export default function SecuritySettingsPage() {
  const [list, setList] = useState<AuthSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [error, setError] = useState("");
  const [ending, setEnding] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    sessions
      .list()
      .then((res) => setList(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const end = async (session: AuthSession) => {
    setError("");
    setEnding(session.uuid);

    try {
      await sessions.end(session.uuid);
      // Ending the current session deletes this browser's own token, so the
      // reload below 401s and the handler in lib/api.ts signs it out properly.
      // No special case here: the server decides, and it already has.
      setList((current) => current.filter((item) => item.uuid !== session.uuid));
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setEnding(null);
    }
  };

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h2 className="text-2xl font-bold text-ink">الأجهزة والجلسات</h2>
        <p className="mt-2 text-sm text-ink-muted">
          هذه هي الأجهزة التي سُجِّل الدخول إلى حسابك منها الآن. إن رأيت جهازاً لا
          تعرفه، أنهِ جلسته ثم غيّر كلمة مرورك.
        </p>
      </div>

      {error !== "" && <Alert tone="danger" title={error} />}

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : list.length === 0 ? (
        <EmptyState
          title="لا توجد جلسات نشطة"
          description="لا يوجد جهاز مسجَّل دخوله إلى حسابك غير هذا الجهاز."
        />
      ) : (
        <ul className="space-y-3">
          {list.map((session) => (
            <li key={session.uuid}>
              <Card as="article" padding="sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-semibold text-ink">
                        {session.device.label}
                      </span>
                      {session.is_current && <Badge tone="success">هذا الجهاز</Badge>}
                    </div>
                    <p className="mt-1 text-xs text-ink-muted">
                      آخر نشاط: {formatDate(session.last_active_at)} · بدأت في{" "}
                      {formatDate(session.created_at)}
                    </p>
                  </div>

                  <Button
                    variant="danger"
                    size="sm"
                    onClick={() => end(session)}
                    loading={ending === session.uuid}
                    loadingLabel="جارٍ الإنهاء…"
                  >
                    {session.is_current ? "سجّل الخروج من هنا" : "أنهِ الجلسة"}
                  </Button>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      )}

      <TwoFactorSection />
    </div>
  );
}

/**
 * Enrolment, in the one place the account holder controls their own security.
 *
 * The recovery codes are shown exactly once, because they are stored hashed —
 * there is no endpoint that could show them again, and the screen has to say so
 * rather than imply a list waiting somewhere.
 */
function TwoFactorSection() {
  const [state, setState] = useState<TwoFactorState | null>(null);
  const [uri, setUri] = useState<string | null>(null);
  const [codes, setCodes] = useState<string[] | null>(null);
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    twoFactor.state().then(setState).catch(() => setState(null));
  }, []);

  useEffect(load, [load]);

  const run = async (work: () => Promise<void>) => {
    setError("");
    setBusy(true);

    try {
      await work();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  /**
   * The base32 secret out of the `otpauth://` URI.
   *
   * Read here rather than requested from the API, because the URI already
   * carries it and a second field would be a second copy of a secret to keep in
   * step. A regex rather than `new URL()`: the custom scheme parses
   * inconsistently across browsers, and failing to read the key is the one
   * outcome this screen cannot afford.
   */
  const setupKey = uri === null ? null : (/[?&]secret=([A-Z2-7]+)/i.exec(uri)?.[1] ?? null);

  const start = () =>
    run(async () => {
      const { otpauth_uri } = await twoFactor.setup(password);
      setUri(otpauth_uri);
      setPassword("");
    });

  const confirm = () =>
    run(async () => {
      const result = await twoFactor.confirm(code);
      setCodes(result.recovery_codes);
      setUri(null);
      setCode("");
      load();
    });

  const disable = () =>
    run(async () => {
      await twoFactor.disable(password, code);
      setPassword("");
      setCode("");
      setCodes(null);
      load();
    });

  if (state === null) return null;

  const deadline = state.required_at !== null && !state.enabled;

  return (
    <Card as="section">
      <h3 className="text-lg font-semibold text-ink">التحقق بخطوتين</h3>
      <p className="mt-2 text-sm text-ink-muted">
        رمز من تطبيق مصادقة بجانب كلمة المرور. من دونه، كلمة مرور مسرّبة تكفي وحدها
        للدخول إلى حسابك.
      </p>

      {error !== "" && (
        <div className="mt-4">
          <Alert tone="danger" title={error} />
        </div>
      )}

      {deadline && (
        <div className="mt-4">
          <Alert tone="warning" title="مطلوب على حسابك">
            فعّل التحقق بخطوتين قبل {formatDate(state.required_at)}. بعد هذا التاريخ
            ستتوقّف العمليات الحسّاسة مثل اعتماد المدفوعات وإدارة الأعضاء.
          </Alert>
        </div>
      )}

      {codes !== null && (
        <div className="mt-4 space-y-3">
          <Alert tone="warning" title="احفظ رموز الاسترداد الآن">
            هذه هي المرة الوحيدة التي تظهر فيها. كل رمز يعمل مرة واحدة، وهو طريقك
            إلى حسابك إن فقدت هاتفك.
          </Alert>
          <ul className="grid grid-cols-2 gap-2 rounded-xl border border-line p-4 font-mono text-sm text-ink">
            {codes.map((item) => (
              <li key={item} dir="ltr" className="text-start">
                {item}
              </li>
            ))}
          </ul>
        </div>
      )}

      {state.enabled ? (
        <div className="mt-4 space-y-4">
          <div className="flex items-center gap-2">
            <Badge tone="success">مفعَّل</Badge>
            <span className="text-sm text-ink-muted">
              رموز استرداد متبقّية: {state.recovery_codes_remaining}
            </span>
          </div>

          <PasswordField
            id="disable-password"
            label="كلمة المرور الحالية"
            value={password}
            onChange={setPassword}
            autoComplete="current-password"
          />
          <TextField
            id="disable-code"
            label="الرمز من التطبيق"
            value={code}
            onChange={setCode}
            autoComplete="one-time-code"
            maxLength={32}
          />

          <div className="flex flex-wrap gap-3">
            <Button variant="danger" size="sm" onClick={disable} loading={busy}>
              إلغاء التحقق بخطوتين
            </Button>
            <Button
              variant="secondary"
              size="sm"
              onClick={() =>
                run(async () => {
                  const result = await twoFactor.regenerateRecoveryCodes();
                  setCodes(result.recovery_codes);
                  load();
                })
              }
              loading={busy}
            >
              رموز استرداد جديدة
            </Button>
          </div>
        </div>
      ) : uri !== null ? (
        <div className="mt-4 space-y-4">
          <p className="text-sm text-ink-muted">
            افتح تطبيق المصادقة واختر «إدخال مفتاح الإعداد»، ثم الصق المفتاح
            التالي وأدخل الرمز الذي يعرضه التطبيق.
          </p>

          {/*
            ⚠️ THE KEY, NOT THE URI. This block used to print the whole
            `otpauth://…` string under "add an account with this link", and an
            authenticator's manual-entry field takes only the base32 secret — so
            pasting what the screen offered was refused as an invalid or
            too-short key. `otpauth://` is a target for a camera or a tap, never
            something a person types.
          */}
          <code
            dir="ltr"
            className="block overflow-x-auto rounded-xl border border-line p-3 text-center text-base font-bold tracking-widest text-ink"
          >
            {setupKey ?? uri}
          </code>

          {/* On a phone this opens the authenticator with the account already
              filled in, which is the path that needs no typing at all. */}
          <a
            href={uri}
            className="block text-sm font-medium text-primary-ink underline underline-offset-4"
          >
            فتح تطبيق المصادقة مباشرةً
          </a>

          <TextField
            id="confirm-code"
            label="الرمز من التطبيق"
            value={code}
            onChange={setCode}
            autoComplete="one-time-code"
            maxLength={6}
          />

          <Button size="sm" onClick={confirm} loading={busy}>
            تأكيد التفعيل
          </Button>
        </div>
      ) : (
        <div className="mt-4 space-y-4">
          <PasswordField
            id="setup-password"
            label="كلمة المرور الحالية"
            value={password}
            onChange={setPassword}
            autoComplete="current-password"
          />

          <Button size="sm" onClick={start} loading={busy}>
            ابدأ التفعيل
          </Button>
        </div>
      )}
    </Card>
  );
}
