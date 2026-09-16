import type { Metadata } from "next";
import { platformName } from "@/lib/platform";
import { FloatingActions } from "@/components/ui/FloatingActions";

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

/*
 * Chrome is otherwise the root layout's and the shell's; this group adds one
 * thing.
 *
 * ⚠️ THE WAY TO ASK FOR HELP WAS MOUNTED IN `(public)` ALONE, so the entire
 * signed-in product had none — the student stuck on a receipt, the teacher whose
 * room will not open, the visitor who cannot get past `/login`. The marketplace,
 * where nobody is stuck on anything yet, was the one place it was offered.
 *
 * ⚠️ AND IT IS HERE RATHER THAN IN `(shell)`, WHICH IS THE WHOLE POINT: `/login`,
 * `/register`, `/invitations` and the certificate verifier live in this group and
 * NOT in the shell. Someone who cannot sign in is exactly the reader who needs a
 * human and has no other channel — the same argument `PlatformIdentityController`
 * makes for answering this number without authentication.
 */
export default function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <>
      {children}
      <FloatingActions />
    </>
  );
}
