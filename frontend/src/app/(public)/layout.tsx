import type { Metadata } from "next";
import { Cairo } from "next/font/google";
import "../globals.css";
import { SiteHeader } from "@/components/marketplace/SiteHeader";
import { SiteFooter } from "@/components/marketplace/SiteFooter";
import { PLATFORM_NAME } from "@/lib/platform";

// Self-hosted by next/font — no runtime request to Google, which would otherwise
// block first paint on the very metric SC-007 measures.
const cairo = Cairo({
  subsets: ["arabic", "latin"],
  weight: ["400", "600", "700"],
  variable: "--font-cairo",
  display: "swap",
});

export const metadata: Metadata = {
  title: {
    default: `${PLATFORM_NAME} — دروس خصوصية مباشرة ومسجّلة`,
    template: `%s | ${PLATFORM_NAME}`,
  },
  description:
    "منصة عربية تربط الطلاب بأفضل المدرّسين لحصص خصوصية فردية وجماعية، مباشرة ومسجّلة، مع نظام تقييم ودرجة ثقة لكل مدرّس.",
};

// Runs before paint so the saved choice wins over the OS preference without a
// flash of the wrong theme (FR-084).
const THEME_SCRIPT = `
(function () {
  try {
    var saved = localStorage.getItem('theme');
    var system = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.dataset.theme = saved || system;
  } catch (e) {
    document.documentElement.dataset.theme = 'light';
  }
})();
`;

export default function PublicLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    // dir/lang live on this group, not the root layout: flipping the root would
    // turn the entire authenticated app RTL, which is out of scope here.
    <html lang="ar" dir="rtl" className={cairo.variable} suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: THEME_SCRIPT }} />
      </head>
      <body className="min-h-screen bg-surface font-sans text-ink antialiased">
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:right-4 focus:z-50 focus:rounded-lg focus:bg-primary focus:px-4 focus:py-2 focus:text-white"
        >
          تخطَّ إلى المحتوى الرئيسي
        </a>
        <SiteHeader />
        <main id="main">{children}</main>
        <SiteFooter />
      </body>
    </html>
  );
}
