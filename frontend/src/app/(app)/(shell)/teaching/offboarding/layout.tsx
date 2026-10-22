import type { Metadata } from "next";

// The page is a client component, so its tab title lives here — without it the
// tab read the group's default, «لوحة التحكم» (co-teacher journey 2026-10-22).
export const metadata: Metadata = { title: "إنهاء النشاط" };

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
