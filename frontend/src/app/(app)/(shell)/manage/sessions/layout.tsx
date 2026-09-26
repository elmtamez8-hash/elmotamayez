import type { Metadata } from "next";

// The page is a client component, so its tab title lives here; the `(app)`
// group's template appends the platform name. Without it this screen read the
// group's default, «لوحة التحكم».
export const metadata: Metadata = { title: "الحصص" };

export default function ManageSessionsLayout({ children }: { children: React.ReactNode }) {
  return children;
}
