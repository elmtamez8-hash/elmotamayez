import type { Metadata } from "next";
import Link from "next/link";
import { publicApi } from "@/lib/public-api";
import { StudentSignupForm } from "@/components/marketplace/StudentSignupForm";
import { PLATFORM_NAME } from "@/lib/platform";

export const metadata: Metadata = {
  title: `تسجيل طالب — ${PLATFORM_NAME}`,
  description: "أنشئ حساب طالب واحجز حصصك مع أفضل المدرسين.",
};

type Search = { teacher?: string; trial?: string };

export default async function StudentSignupPage({
  searchParams,
}: {
  searchParams: Promise<Search>;
}) {
  const { teacher, trial } = await searchParams;

  // Fetched on the server so the year list is in the HTML: the form is useless
  // without it, and a client fetch would leave a blank select on a slow
  // connection.
  //
  // ⚠️ `publicApi.schoolYears()`, NOT `gradeLevels()` (spec 022). The old call
  // went to the marketplace read, which drops every entry with no publicly
  // listed teacher — so on a platform with nobody approved yet, this required
  // field rendered a select with NOTHING in it and no student could register at
  // all. That is the defect this spec exists for.
  const [schoolYears, regions] = await Promise.all([
    publicApi.schoolYears(),
    // Spec 011 · FR-042 — required by the API, so a form rendered without it
    // could only ever be answered 422.
    publicApi.regions(),
  ]);

  return (
    <div className="mx-auto max-w-2xl px-4 py-12 sm:px-6">
      <div className="mb-8 text-center">
        <h1 className="mb-2 text-2xl font-extrabold text-ink sm:text-3xl">
          إنشاء حساب طالب
        </h1>
        <p className="text-ink-muted">
          خطوة واحدة وتبدأ التعلّم مع مدرّس تختاره بنفسك.
        </p>
      </div>

      {teacher && (
        <p className="mb-6 rounded-xl bg-primary-soft p-4 text-sm text-primary-ink">
          {trial
            ? "بعد إنشاء الحساب ستعود لصفحة المدرّس لإتمام حجز الحصة التجريبية."
            : "بعد إنشاء الحساب ستعود لصفحة المدرّس لإتمام الحجز."}{" "}
          <Link href={`/teachers/${teacher}`} className="underline">
            العودة لصفحة المدرّس
          </Link>
        </p>
      )}

      <StudentSignupForm schoolYears={schoolYears} regions={regions} teacherUuid={teacher} />
    </div>
  );
}
