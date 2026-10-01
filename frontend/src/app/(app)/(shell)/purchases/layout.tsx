import type { Metadata } from "next";

// The page is a client component, so its tab title lives here (see orders/layout.tsx).
export const metadata: Metadata = { title: "مشترياتي" };

export default function PurchasesLayout({ children }: { children: React.ReactNode }) {
  return children;
}
