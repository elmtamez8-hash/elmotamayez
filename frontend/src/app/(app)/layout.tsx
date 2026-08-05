import type { Metadata } from "next";
import { AuthProvider } from "@/lib/auth-context";
import { PLATFORM_NAME } from "@/lib/platform";

export const metadata: Metadata = {
  title: {
    default: `لوحة التحكم | ${PLATFORM_NAME}`,
    template: `%s | ${PLATFORM_NAME}`,
  },
  description: "إدارة كورساتك وحصصك واختباراتك وشهاداتك.",
};

// Auth context only. `<html>` and `<body>` moved to the root layout in 002 — this
// group used to declare `lang="en"` and its own LTR body, which is what made
// logging in feel like leaving the product.
export default function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <AuthProvider>{children}</AuthProvider>;
}
