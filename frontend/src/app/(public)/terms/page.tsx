import type { Metadata } from "next";
import { PolicyPlaceholder } from "@/components/marketplace/PolicyPlaceholder";

export const metadata: Metadata = {
  title: "الشروط والأحكام",
  // noindex until the approved text exists: an indexed placeholder is a search
  // result promising a policy the visitor will not find.
  robots: { index: false },
};

export default function TermsPage() {
  return (
    <PolicyPlaceholder
      title="الشروط والأحكام"
      summary="القواعد التي تحكم استخدام المنصة للطلاب وأولياء الأمور والمدرّسين."
    />
  );
}
