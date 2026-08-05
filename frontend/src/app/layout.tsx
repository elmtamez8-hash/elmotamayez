import type { Metadata } from "next";
import { Cairo } from "next/font/google";
import "./globals.css";
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
// flash of the wrong theme (FR-010). It has to live in the ROOT layout, not in a
// route group: a group-level script leaves every page outside that group — the
// whole authenticated panel — flashing light before it can read localStorage.
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

/**
 * The one root layout. Before 002 there were two competing ones — `(public)` in
 * Arabic RTL and `(app)` in English LTR — which is why a user crossed a language
 * boundary by logging in. Direction, language, font and theme are declared here
 * once (FR-001, FR-002, FR-021); route groups below add only their own chrome.
 */
export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="ar" dir="rtl" className={cairo.variable} suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: THEME_SCRIPT }} />
      </head>
      <body className="min-h-screen bg-surface font-sans text-ink antialiased">
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:z-50 focus:rounded-lg focus:bg-primary focus:px-4 focus:py-2 focus:text-white focus:start-4"
        >
          تخطَّ إلى المحتوى الرئيسي
        </a>
        {children}
      </body>
    </html>
  );
}
