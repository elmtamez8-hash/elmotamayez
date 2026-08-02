import type { Metadata } from "next";
import { publicApi } from "@/lib/public-api";
import { AddChildForm } from "@/components/marketplace/AddChildForm";
import { PLATFORM_NAME } from "@/lib/platform";

export const metadata: Metadata = {
  title: `إضافة طفل — ${PLATFORM_NAME}`,
  // Only reachable with a session, and only meaningful to the parent who just
  // signed up. Nothing here belongs in a search result.
  robots: { index: false },
};

export default async function AddChildrenPage() {
  // Server-fetched so the grade list is in the HTML: the form is useless without
  // it, and a client fetch leaves an empty select on a slow connection.
  const gradeLevels = await publicApi.gradeLevels();

  return (
    <div className="mx-auto max-w-xl px-4 py-12 sm:px-6">
      <div className="mb-8 text-center">
        <h1 className="mb-2 text-2xl font-extrabold text-ink sm:text-3xl">
          أضف أبناءك
        </h1>
        <p className="text-ink-muted">
          اربط حساب كل طفل لمتابعة حصصه وتقاريره. يمكنك تخطّي هذه الخطوة والعودة
          إليها في أي وقت.
        </p>
      </div>

      <AddChildForm gradeLevels={gradeLevels} />
    </div>
  );
}
