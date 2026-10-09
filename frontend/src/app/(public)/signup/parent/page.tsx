import type { Metadata } from "next";
import { ParentSignupForm } from "@/components/marketplace/ParentSignupForm";
import { SignupFrame } from "@/components/marketplace/SignupFrame";

export const metadata: Metadata = {
  title: "تسجيل وليّ أمر",
  description:
    "أنشئ حساب وليّ أمر لمتابعة حصص أبنائك وتقاريرهم الأسبوعية واختيار المدرّس المناسب لهم.",
  // A registration form is a door, not a page to land on from a search result —
  // and its fields would be all a crawler indexed. `follow` keeps the links.
  robots: { index: false, follow: true },
};

export default function ParentSignupPage() {
  return (
    <SignupFrame
      role="parent"
      title="تسجيل وليّ أمر"
      subtitle="تابع حصص أبنائك وتقدّمهم من مكان واحد. تضيف أبناءك في الخطوة التالية."
    >
      <ParentSignupForm />
    </SignupFrame>
  );
}
