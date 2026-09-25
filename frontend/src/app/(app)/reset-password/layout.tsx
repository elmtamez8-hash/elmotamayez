import type { Metadata } from "next";

/*
 * The page is a client component, so its title lives here — see
 * `login/layout.tsx` for why the tab used to read «لوحة التحكم».
 */
export const metadata: Metadata = {
  title: "اختر كلمة مرور جديدة",
  description: "عيّن كلمة مرور جديدة لحسابك.",
  // Reached only through a one-time link from an email: nothing to index.
  robots: { index: false },
};

export default function ResetPasswordLayout({ children }: { children: React.ReactNode }) {
  return children;
}
