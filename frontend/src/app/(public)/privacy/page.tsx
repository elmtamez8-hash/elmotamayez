import type { Metadata } from "next";
import { PolicyPlaceholder } from "@/components/marketplace/PolicyPlaceholder";
import { ShieldIcon } from "@/components/icons";

export const metadata: Metadata = {
  title: "سياسة الخصوصية",
  robots: { index: false },
};

export default function PrivacyPage() {
  return (
    <PolicyPlaceholder
      icon={ShieldIcon}
      image="/marketplace/banner-privacy.webp"
      tone="ink"
      title="سياسة الخصوصية"
      summary="ما البيانات التي نجمعها، ولماذا، ومن يطّلع عليها، وكيف تطلب حذفها."
    />
  );
}
