"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import { referrals, type Referral, type ReferralCode } from "@/lib/referrals";

/**
 * Invite a friend (spec 011 · US3 · FR-018 · FR-019).
 *
 * ⚠️ THE PAGE SAYS WHEN THE REWARD ARRIVES, NOT JUST THAT ONE EXISTS. A referral
 * pays on the friend's first real subscription and on nothing else (FR-019), so
 * a screen that promises points for «inviting» is a screen that generates
 * support tickets from the first day: the inviter sees a signup, sees nothing
 * happen, and reports it broken. The status label on every row carries the same
 * sentence in miniature.
 *
 * ⚠️ AND NO NUMBER IS PROMISED HERE. What an invitation is worth is a
 * `gamification_actions` row an operator edits from the panel; a figure written
 * into this component is a second live source for it, and the one that drifts is
 * always the one nobody reads. The count of completed invitations IS shown,
 * because that is a fact about this person.
 *
 * ⚠️ THE INVITED PERSON IS NEVER NAMED, by omission from the API rather than by
 * a decision here. The inviter already knows who they invited; a list of names
 * and dates assembled out of other people's signups is a contact list nobody
 * consented to.
 */
export default function ReferralsPage() {
  const [code, setCode] = useState<ReferralCode | null>(null);
  const [rows, setRows] = useState<Referral[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const load = useCallback(async () => {
    setState("loading");

    try {
      // The code endpoint MINTS on first call, which is why it is fetched here
      // rather than being expected to exist: every account older than this
      // feature gets one the moment its owner opens this page.
      const [issued, list] = await Promise.all([referrals.code(), referrals.list()]);

      setCode(issued);
      setRows(list.data);
      setState("ready");
    } catch (error) {
      setProblem(userMessage(error));
      setState("error");
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function copy() {
    if (code === null) return;

    try {
      await navigator.clipboard.writeText(code.code);
      setCopied(true);
    } catch {
      // ⚠️ NOT AN ERROR SCREEN. The clipboard is refused outside a secure
      // context and in some in-app browsers, and the code is on screen and
      // selectable either way — telling somebody their invitation failed
      // because a copy button did not work would be false.
      setCopied(false);
    }
  }

  if (state === "loading") return <RowsSkeleton />;
  if (state === "error") return <ErrorState description={problem ?? undefined} onRetry={() => void load()} />;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">دعوة صديق</h1>
        <p className="mt-1 text-sm text-ink-muted">
          شارِكْ كودك مع من تعرف. حين يشترك صديقك اشتراكاً فعليّاً تُضاف نقاط لكما معاً.
        </p>
      </header>

      <Card>
        <div className="space-y-3">
          <p className="text-sm text-ink-muted">كودك</p>

          <p className="font-mono text-2xl font-semibold tracking-widest text-ink" dir="ltr">
            {code?.code}
          </p>

          <div className="flex items-center gap-3">
            <Button type="button" variant="secondary" onClick={() => void copy()}>
              نسخ الكود
            </Button>

            {copied && <span className="text-sm text-secondary-ink">نُسخ.</span>}
          </div>

          <Alert tone="info" title="متى تصل النقاط؟">
            يكتب صديقك الكود عند إنشاء حسابه، ثمّ تُضاف النقاط لكما عندما يشترك اشتراكاً فعليّاً
            ويُعتمَد دفعُه — لا عند التسجيل وحدَه. وإن استُرِدّ الاشتراك تُسحَب النقاط.
          </Alert>
        </div>
      </Card>

      <Card>
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-semibold text-ink">دعواتك</h2>
            <Badge tone="neutral">اكتملت: {code?.completed_count ?? 0}</Badge>
          </div>

          {rows.length === 0 ? (
            <EmptyState title="لم تُرسِلْ دعوةً بعد" description="شارِكْ كودك أعلاه لتبدأ." />
          ) : (
            <ul className="divide-y divide-line">
              {rows.map((row) => (
                <li key={row.uuid} className="flex items-center justify-between py-3">
                  {/* The DATE, because the person is deliberately not named — it
                      is what an inviter recognises a row by when they have sent
                      several. */}
                  <span className="text-sm text-ink-muted">
                    {row.invited_at === null ? "—" : formatDate(row.invited_at)}
                  </span>

                  <Badge tone={row.status === "completed" ? "success" : "neutral"}>
                    {row.status_label}
                  </Badge>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>
    </div>
  );
}
