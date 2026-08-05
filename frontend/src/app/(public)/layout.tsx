import { SiteHeader } from "@/components/marketplace/SiteHeader";
import { SiteFooter } from "@/components/marketplace/SiteFooter";
import { FloatingActions } from "@/components/marketplace/FloatingActions";

// Chrome only. `<html>`, `<body>`, the font, the theme script and the skip link
// moved to the root layout in 002 so the authenticated panel inherits them too.
export default function PublicLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <>
      <SiteHeader />
      <main id="main">{children}</main>
      <SiteFooter />
      <FloatingActions />
    </>
  );
}
