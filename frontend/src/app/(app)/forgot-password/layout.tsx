import type { Metadata } from "next";

/*
 * The page is a client component, so its title lives here — see
 * `login/layout.tsx` for why the tab used to read «لوحة التحكم».
 */
export const metadata: Metadata = {
  title: "استعادة كلمة المرور",
  description: "اطلب رابطاً لإعادة تعيين كلمة مرور حسابك.",
};

export default function ForgotPasswordLayout({ children }: { children: React.ReactNode }) {
  return children;
}
