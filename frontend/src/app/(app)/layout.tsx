import type { Metadata } from "next";
import { PLATFORM_NAME } from "@/lib/platform";

export const metadata: Metadata = {
  title: {
    default: `لوحة التحكم | ${PLATFORM_NAME}`,
    template: `%s | ${PLATFORM_NAME}`,
  },
  description: "إدارة كورساتك وحصصك واختباراتك وشهاداتك.",
};

// Metadata only now. `<html>` and `<body>` moved to the root layout in 002, and
// the auth context followed them there: held here, it made the marketplace a
// place where nobody was ever signed in.
export default function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <>{children}</>;
}
