import type { Metadata } from "next";

/*
 * The page is a client component, so its title lives here — see
 * `login/layout.tsx` for why the tab used to read «لوحة التحكم».
 */
export const metadata: Metadata = {
  title: "إنشاء حساب",
  description: "أنشئ حسابك على المنصّة.",
  // A registration form is a door, not a page to land on from a search result —
  // and its fields would be all a crawler indexed. `follow` keeps the links.
  robots: { index: false, follow: true },
};

export default function RegisterLayout({ children }: { children: React.ReactNode }) {
  return children;
}
