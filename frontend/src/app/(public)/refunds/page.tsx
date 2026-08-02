import type { Metadata } from "next";
import { PolicyPlaceholder } from "@/components/marketplace/PolicyPlaceholder";

export const metadata: Metadata = {
  title: "سياسة الاسترجاع",
  robots: { index: false },
};

export default function RefundsPage() {
  return (
    <PolicyPlaceholder
      title="سياسة الاسترجاع"
      summary="متى يحقّ لك استرداد قيمة حصة أو كورس، وكيف تُقدَّم الطلبات ومدة معالجتها."
    />
  );
}
