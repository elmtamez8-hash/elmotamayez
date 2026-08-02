import type { Metadata } from "next";
import { PolicyPlaceholder } from "@/components/marketplace/PolicyPlaceholder";

export const metadata: Metadata = {
  title: "سياسة الخصوصية",
  robots: { index: false },
};

export default function PrivacyPage() {
  return (
    <PolicyPlaceholder
      title="سياسة الخصوصية"
      summary="ما البيانات التي نجمعها، ولماذا، ومن يطّلع عليها، وكيف تطلب حذفها."
    />
  );
}
