import type { Metadata } from "next";

/*
 * The page is a client component, so its title lives here — see
 * `login/layout.tsx` for why the tab used to read «لوحة التحكم».
 */
export const metadata: Metadata = {
  title: "إنشاء حساب",
  description: "أنشئ حسابك على المنصّة.",
};

export default function RegisterLayout({ children }: { children: React.ReactNode }) {
  return children;
}
