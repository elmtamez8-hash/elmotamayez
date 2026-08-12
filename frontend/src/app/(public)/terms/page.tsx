import type { Metadata } from "next";
import { PolicyPlaceholder } from "@/components/marketplace/PolicyPlaceholder";
import { DocumentIcon } from "@/components/icons";

export const metadata: Metadata = {
  title: "الشروط والأحكام",
  // noindex until the approved text exists: an indexed placeholder is a search
  // result promising a policy the visitor will not find.
  robots: { index: false },
};

export default function TermsPage() {
  return (
    <PolicyPlaceholder
      icon={DocumentIcon}
      image="/marketplace/banner-terms.webp"
      title="الشروط والأحكام"
      summary="القواعد التي تحكم استخدام المنصة للطلاب وأولياء الأمور والمدرّسين."
    />
  );
}
