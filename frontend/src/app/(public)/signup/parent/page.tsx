import type { Metadata } from "next";
import { ParentSignupForm } from "@/components/marketplace/ParentSignupForm";
import { platformName } from "@/lib/platform";

export async function generateMetadata(): Promise<Metadata> {
  const name = await platformName();

  return {
    title: `تسجيل وليّ أمر — ${name}`,
    description:
      "أنشئ حساب وليّ أمر لمتابعة حصص أبنائك وتقاريرهم الأسبوعية واختيار المدرّس المناسب لهم.",
  };
}

export default function ParentSignupPage() {
  return (
    <div className="mx-auto max-w-xl px-4 py-12 sm:px-6">
      <div className="mb-8 text-center">
        <h1 className="mb-2 text-2xl font-extrabold text-ink sm:text-3xl">
          تسجيل وليّ أمر
        </h1>
        <p className="text-ink-muted">
          تابع حصص أبنائك وتقدّمهم من مكان واحد. تضيف أبناءك في الخطوة التالية.
        </p>
      </div>

      <ParentSignupForm />
    </div>
  );
}
