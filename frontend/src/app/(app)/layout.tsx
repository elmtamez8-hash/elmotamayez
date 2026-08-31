import type { Metadata } from "next";
import { platformName } from "@/lib/platform";

/*
 * ⚠️ THE DEFAULT IS BARE, AND IT USED TO REPEAT THE NAME. Measured live on
 * 2026-08-31: `<title>لوحة التحكم | منصّتي | منصّتي</title>`. The ROOT layout
 * already declares `template: '%s | {name}'`, and a nested group's `default`
 * flows through that template — so a default that ends in the name gets the name
 * appended a second time, on every panel page. The template here is kept for the
 * pages of this group that set their own title; only the default was wrong.
 */
export async function generateMetadata(): Promise<Metadata> {
  const name = await platformName();

  return {
    title: {
      default: "لوحة التحكم",
      template: `%s | ${name}`,
    },
    description: "إدارة كورساتك وحصصك واختباراتك وشهاداتك.",
  };
}

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
