import type { Metadata } from "next";
import { publicApi } from "@/lib/public-api";
import { AddChildForm } from "@/components/marketplace/AddChildForm";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { platformName } from "@/lib/platform";

export async function generateMetadata(): Promise<Metadata> {
  const name = await platformName();

  return {
    title: `إضافة طفل — ${name}`,
    // Only reachable with a session, and only meaningful to the parent who just
    // signed up. Nothing here belongs in a search result.
    robots: { index: false },
  };
}

/*
  ⚠️ RENDERED PER REQUEST, NEVER PRERENDERED — AND IT TOOK DOWN A WHOLE DEPLOY.
  This page fetches the API while rendering, so Next tried to fetch it at BUILD
  time, where no API exists: `TypeError: fetch failed … ECONNREFUSED`, and the
  build exits. The page is only reachable with a session and carries
  `robots: index: false`, so a prerendered copy was never worth anything — and a
  build-time snapshot of the grade list goes stale the first time somebody edits
  the taxonomy.
*/
export const dynamic = "force-dynamic";

export default async function AddChildrenPage() {
  // Server-fetched so the year list is in the HTML: the form is useless without
  // it, and a client fetch leaves an empty select on a slow connection.
  //
  // ⚠️ THE SIGNUP READ, NOT `publicApi.gradeLevels()` (spec 022 · FR-002) —
  // that one drops every entry with no publicly listed teacher, so on a young
  // platform the picker was empty and a parent could name no year at all.
  //
  // ⚠️ AND THE FAILURE IS CAUGHT. Uncaught, an API blip becomes a 500 page — a
  // raw error shown to a parent mid-signup, which this product forbids outright.
  let schoolYears;

  try {
    schoolYears = await publicApi.schoolYears();
  } catch {
    return (
      <div className="mx-auto max-w-xl px-4 py-20 sm:px-6">
        <ErrorState />
      </div>
    );
  }

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

      <AddChildForm schoolYears={schoolYears} />
    </div>
  );
}
