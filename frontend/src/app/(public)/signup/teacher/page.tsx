import type { Metadata } from "next";
import { publicApi } from "@/lib/public-api";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { TeacherSignupWizard } from "@/components/marketplace/TeacherSignupWizard";
import { PLATFORM_NAME } from "@/lib/platform";

export const metadata: Metadata = {
  title: `التقديم كمدرّس — ${PLATFORM_NAME}`,
  description:
    "قدّم طلبك للتدريس على المنصة في أربع خطوات: بياناتك، تخصصك، المستندات، ثم السعر والتوفّر.",
};

/*
  ⚠️ THE SIBLING OF `signup/parent/children`, AND THE SAME TRAP. Prerendering a
  page that fetches the API means the BUILD needs a running API; it does not have
  one, and the failure names a network error rather than the page that caused it.
*/
export const dynamic = "force-dynamic";

export default async function TeacherSignupPage() {
  // Server-fetched so the subject and grade lists are in the HTML: the applicant
  // picks from them on step 2, and a client fetch would leave that screen empty
  // on a slow connection.
  let subjects, gradeLevels;

  try {
    [subjects, gradeLevels] = await Promise.all([
      // ⚠️ THE SIGNUP READS, NOT THE MARKETPLACE ONES (spec 022 · FR-002).
      // `publicApi.subjects()` drops every subject with no publicly listed
      // teacher — so on a platform whose first teacher is filling in this very
      // form, step 2 offered an empty list and the application could not be
      // completed by anybody, ever.
      publicApi.signupSubjects(),
      publicApi.signupGradeLevels(),
    ]);
  } catch {
    return (
      <div className="mx-auto max-w-3xl px-4 py-20 sm:px-6">
        <ErrorState />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl px-4 py-12 sm:px-6">
      <div className="mb-8 text-center">
        <h1 className="mb-2 text-2xl font-extrabold text-ink sm:text-3xl">
          انضم كمدرّس
        </h1>
        <p className="text-ink-muted">
          أربع خطوات، ويمكنك التوقّف والعودة في أي وقت — بياناتك محفوظة.
        </p>
      </div>

      <TeacherSignupWizard subjects={subjects} gradeLevels={gradeLevels} />
    </div>
  );
}
