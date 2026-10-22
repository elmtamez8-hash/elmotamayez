import type { Metadata } from "next";

// The page is a client component, so its tab title lives here — without it the
// tab read the group's default, «لوحة التحكم» (assistant journey 2026-10-21).
export const metadata: Metadata = { title: "اختبار جديد" };

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
