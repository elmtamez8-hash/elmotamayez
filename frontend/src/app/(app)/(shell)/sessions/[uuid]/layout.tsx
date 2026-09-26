import type { Metadata } from "next";

// The page is a client component, so its tab title lives here; the `(app)`
// group's template appends the platform name. Without it this screen read the
// group's default, «لوحة التحكم». The room below sets its own.
export const metadata: Metadata = { title: "الحصة" };

export default function SessionLayout({ children }: { children: React.ReactNode }) {
  return children;
}
