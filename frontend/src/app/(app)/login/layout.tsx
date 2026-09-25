import type { Metadata } from "next";

/*
 * ⚠️ THE PAGE IS A CLIENT COMPONENT, SO ITS TITLE LIVES HERE. A `"use client"`
 * page cannot export `metadata`, and without this file the tab read the `(app)`
 * group's default — «لوحة التحكم» — on a screen for somebody who is not signed
 * in yet. The group's template appends the platform name.
 */
export const metadata: Metadata = {
  title: "تسجيل الدخول",
  description: "سجّل الدخول إلى حسابك لمتابعة كورساتك وحصصك.",
};

export default function LoginLayout({ children }: { children: React.ReactNode }) {
  return children;
}
