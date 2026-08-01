import type { Metadata } from "next";
import { publicApi } from "@/lib/public-api";
import { TeacherSignupWizard } from "@/components/marketplace/TeacherSignupWizard";
import { PLATFORM_NAME } from "@/lib/platform";

export const metadata: Metadata = {
  title: `التقديم كمدرّس — ${PLATFORM_NAME}`,
  description:
    "قدّم طلبك للتدريس على المنصة في أربع خطوات: بياناتك، تخصصك، المستندات، ثم السعر والتوفّر.",
};

export default async function TeacherSignupPage() {
  // Server-fetched so the subject and grade lists are in the HTML: the applicant
  // picks from them on step 2, and a client fetch would leave that screen empty
  // on a slow connection.
  const [subjects, gradeLevels] = await Promise.all([
    publicApi.subjects(),
    publicApi.gradeLevels(),
  ]);

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
