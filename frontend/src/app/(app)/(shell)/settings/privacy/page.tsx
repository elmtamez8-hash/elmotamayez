"use client";

import { useEffect, useState } from "react";

import { ConsentScreen } from "@/components/compliance/ConsentScreen";
import { DataRequestsPanel } from "@/components/compliance/DataRequestsPanel";
import { Alert } from "@/components/ui/Alert";
import { Card } from "@/components/ui/Card";
import { compliance, type PrivacyPolicy } from "@/lib/compliance";

/**
 * A person's own privacy: what is held about them, and what they consented to.
 *
 * ⚠️ THIS IS NOT `(public)/privacy`, AND THE TWO MUST NOT BE MERGED. That one is
 * the POLICY — the text a visitor reads before creating an account, linked from
 * every footer, and useless behind a login. This one is MY DATA: which optional
 * categories I currently allow, and the requests I have made. Same word, two
 * questions, two audiences.
 */
export default function PrivacyPage() {
  const [policy, setPolicy] = useState<PrivacyPolicy | null>(null);

  useEffect(() => {
    compliance.policy().then(setPolicy).catch(() => setPolicy(null));
  }, []);

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">خصوصيّتي</h1>
        <p className="text-sm text-ink-muted">
          ما نجمعه عنك، ولماذا، ومدّة حفظه — وما يمكنك سحب الموافقة عنه.
        </p>
      </header>

      {policy !== null && (
        <Card>
          <p className="text-sm text-ink-muted">
            النسخة السارية من سياسة الخصوصية: <bdi>{policy.version}</bdi>
          </p>
          {/*
            ⚠️ THE LINK GOES TO THE PUBLIC PAGE, not to a second copy of the text.
            One source, rendered once — a policy shown in two places drifts at the
            first correction, and then the version number stops meaning anything.
          */}
          <a className="text-sm text-primary underline" href="/privacy">
            اقرأ النصّ الكامل
          </a>
        </Card>
      )}

      {/*
        ⚠️ THE SAME COMPONENT THE GUARDIAN SEES. A second screen "for the student"
        would be a second list of categories, and the two would diverge at the
        first row anybody adds — which is exactly how a person ends up consenting
        to something they were never shown.
      */}
      <ConsentScreen />

      <DataRequestsPanel />

      <Alert tone="info" title="طلب الحذف">
        حذف بياناتك متاح قريباً من هذه الصفحة. حتى ذلك الحين راسِلْنا، وسنعالج الطلب خلال
        المدّة المعلَنة في السياسة.
      </Alert>
    </div>
  );
}
