"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { ReferralIcon, UsersIcon, WhatsAppIcon } from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { formatDate } from "@/lib/labels";
import { referralSignupLink, whatsAppShareHref } from "@/lib/referral-link";
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
  const [copied, setCopied] = useState<"code" | "link" | null>(null);
  // Read in an effect, never during render: this page is prerendered, and
  // `window` does not exist there — a mismatch between the server's blank and
  // the client's origin is a hydration warning on every visit.
  const [origin, setOrigin] = useState("");

  useEffect(() => {
    setOrigin(window.location.origin);
  }, []);

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

  const link = code === null || origin === "" ? "" : referralSignupLink(origin, code.code);

  async function copy(what: "code" | "link") {
    if (code === null) return;

    try {
      await navigator.clipboard.writeText(what === "code" ? code.code : link);
      setCopied(what);
    } catch {
      // ⚠️ NOT AN ERROR SCREEN. The clipboard is refused outside a secure
      // context and in some in-app browsers, and the code is on screen and
      // selectable either way — telling somebody their invitation failed
      // because a copy button did not work would be false.
      setCopied(null);
    }
  }

  if (state === "loading") return <RowsSkeleton />;
  if (state === "error") return <ErrorState description={problem ?? undefined} onRetry={() => void load()} />;

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={ReferralIcon}
        title="دعوة صديق"
        description="شارِكْ رابط الدعوة أو كودك مع من تعرف. حين يشترك صديقك اشتراكاً فعليّاً تُضاف نقاط لكما معاً."
      />

      <Card>
        <div className="space-y-3">
          <p className="text-sm text-ink-muted">كودك</p>

          <p className="font-mono text-2xl font-semibold tracking-widest text-ink" dir="ltr">
            {code?.code}
          </p>

          <div className="flex items-center gap-3">
            <Button type="button" variant="secondary" onClick={() => void copy("code")}>
              نسخ الكود
            </Button>

            {copied === "code" && <span className="text-sm text-secondary-ink">نُسخ.</span>}
          </div>

          {link !== "" && (
            <div className="space-y-3 border-t border-line pt-3">
              <p className="text-sm text-ink-muted">رابط الدعوة</p>

              {/* Selectable text, so the link survives a refused clipboard. */}
              <p className="break-all font-mono text-sm text-ink" dir="ltr">
                {link}
              </p>

              <div className="flex flex-wrap items-center gap-3">
                <Button type="button" variant="secondary" onClick={() => void copy("link")}>
                  انسخ الرابط
                </Button>

                <Button
                  href={whatsAppShareHref(link)}
                  external
                  variant="secondary"
                  iconStart={<WhatsAppIcon className="h-5 w-5" />}
                >
                  شارك عبر واتساب
                </Button>

                {copied === "link" && <span className="text-sm text-secondary-ink">نُسخ الرابط.</span>}
              </div>
            </div>
          )}

          <Alert tone="info" title="متى تصل النقاط؟">
            أرسل الرابط لصديقك، أو يكتب الكود في خانة «كود الإحالة» عند التسجيل، ثمّ تُضاف النقاط لكما عندما يشترك اشتراكاً فعليّاً
            ويُعتمَد دفعُه — لا عند التسجيل وحدَه. وإن استُرِدّ الاشتراك تُسحَب النقاط.
          </Alert>
        </div>
      </Card>

      <Card as="section">
        <div className="space-y-3">
          <div className="flex items-center justify-between gap-3">
            <SectionHeading id="referrals-invites" Icon={UsersIcon} title="دعواتك" />
            <Badge tone="neutral">اكتملت: {code?.completed_count ?? 0}</Badge>
          </div>

          {rows.length === 0 ? (
            <EmptyState title="لم تُرسِلْ دعوةً بعد" description="شارِكْ رابطك أو كودك أعلاه لتبدأ." />
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
