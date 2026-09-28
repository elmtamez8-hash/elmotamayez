"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Card } from "@/components/ui/Card";
import { CheckboxField } from "@/components/ui/Field";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { P, can } from "@/lib/permissions";

/**
 * «استقبال رسائل من غير المشتركين» — the teacher's own switch (2026-09-28).
 *
 * ON by default: a visitor to the course page may ask a question before paying,
 * limited to a few messages until the teacher answers. OFF shuts out people who
 * do not study here; the teacher's own students write as before.
 *
 * ⚠️ SHOWN ON `settings.update` AND ASKED ON NOTHING ELSE. That is the tenant
 * owner's permission, which no assistant holds — the same gate the endpoint
 * asks, so nobody is shown a switch that answers 403. A learner is never asked.
 */
export function InboxSettingsCard() {
  const { user } = useAuth();
  const allowed = can(user, P.settingsUpdate);

  const [accepts, setAccepts] = useState<boolean | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (!allowed) return;

    api
      .get<{ data: { accepts_prospects: boolean } }>("/inbox-settings")
      .then((response) => setAccepts(response.data.accepts_prospects))
      .catch((error: unknown) => setProblem(userMessage(error)));
  }, [allowed]);

  if (!allowed) return null;

  const change = (next: boolean) => {
    setSaving(true);
    setProblem(null);
    setSaved(false);

    api
      .put<{ data: { accepts_prospects: boolean } }>("/inbox-settings", { accepts_prospects: next })
      .then((response) => {
        setAccepts(response.data.accepts_prospects);
        setSaved(true);
      })
      .catch((error: unknown) => setProblem(userMessage(error)))
      .finally(() => setSaving(false));
  };

  return (
    <Card as="section">
      <h2 className="mb-1 text-base font-bold text-ink">الرسائل من غير المشتركين</h2>
      <p className="mb-4 text-sm text-ink-muted">
        يستطيع من لا يدرس عندك أن يسألك من صفحة الكورس أو صفحتك، ويرسل عدداً محدوداً من الرسائل حتى
        تردّ. طلابك المشتركون يراسلونك دائماً مهما كان هذا الاختيار.
      </p>

      {problem !== null && (
        <div className="mb-3">
          <Alert tone="danger" title="تعذّر الحفظ">
            {problem}
          </Alert>
        </div>
      )}

      {accepts !== null && (
        <CheckboxField
          id="inbox-accepts-prospects"
          label="استقبال رسائل من غير المشتركين"
          checked={accepts}
          onChange={change}
          disabled={saving}
        />
      )}

      {saved && <p className="mt-2 text-xs text-secondary-ink">حُفظ.</p>}
    </Card>
  );
}
