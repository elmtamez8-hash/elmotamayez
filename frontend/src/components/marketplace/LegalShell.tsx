import Link from "next/link";
import type { ComponentType, ReactNode } from "react";
import { AlertIcon, DocumentIcon, RefundIcon, ShieldIcon } from "@/components/icons";
import { PageBanner } from "@/components/ui/PageBanner";
import { LegalToc } from "./LegalToc";

export type LegalPage = "terms" | "privacy" | "refunds";

/**
 * The four doors the footer's «قانوني» column opens, as one row at the top of
 * every legal page — so a reader who came for the refund rules can reach the
 * terms they sit under without going back to the footer. The breach report is
 * a section of /privacy, and it is listed because the footer lists it.
 */
const LEGAL_LINKS: { href: string; label: string; Icon: ComponentType<{ className?: string }>; page: LegalPage | null }[] = [
  { href: "/terms", label: "الشروط والأحكام", Icon: DocumentIcon, page: "terms" },
  { href: "/privacy", label: "سياسة الخصوصية", Icon: ShieldIcon, page: "privacy" },
  { href: "/privacy#breach-report", label: "الإبلاغ عن تسرّب بيانات", Icon: AlertIcon, page: null },
  { href: "/refunds", label: "سياسة الاسترجاع", Icon: RefundIcon, page: "refunds" },
];

/**
 * The frame every legal page shares: the banner, the row of legal pages, and a
 * reading column with a table of contents beside it on wide screens.
 *
 * The column is capped (`max-w-3xl`) because a policy is all prose and
 * full-width lines of it are unreadable — /privacy rendered edge to edge with
 * no container at all until this frame existed.
 */
export function LegalShell({
  current,
  title,
  summary,
  icon,
  image,
  children,
}: {
  current: LegalPage;
  title: string;
  summary: string;
  icon: ComponentType<{ className?: string }>;
  image: string;
  children: ReactNode;
}) {
  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <PageBanner icon={icon} image={image} title={title} description={summary} />

      <nav aria-label="الصفحات القانونية" className="-mt-4 mb-10">
        <ul className="flex flex-wrap gap-2">
          {LEGAL_LINKS.map(({ href, label, Icon, page }) => {
            const active = page === current;

            return (
              <li key={href} className="shrink-0">
                <Link
                  href={href}
                  aria-current={active ? "page" : undefined}
                  className={`inline-flex items-center gap-2 rounded-full border px-4 py-2.5 text-sm font-bold transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                    active
                      ? "border-primary bg-primary text-white shadow-md shadow-primary/20"
                      : "border-line bg-surface-raised text-ink hover:border-primary hover:text-primary-ink"
                  }`}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  {label}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>

      <div className="grid gap-10 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-14">
        <aside className="hidden lg:block">
          <div className="sticky top-28">
            <LegalToc />
          </div>
        </aside>

        <div data-legal-body className="min-w-0 max-w-3xl space-y-8">
          {children}
        </div>
      </div>
    </div>
  );
}

/** The text of a policy, set in a card. */
export function LegalArticle({ children, html }: { children?: ReactNode; html?: string }) {
  const className =
    "prose-policy rounded-3xl border border-line bg-surface-raised p-6 text-ink shadow-sm sm:p-10";

  // `html` is the server's own MarkdownRenderer output (raw HTML stripped from
  // the source, never escaped through) — see the note at the /privacy call site.
  return html !== undefined ? (
    <article className={className} dangerouslySetInnerHTML={{ __html: html }} />
  ) : (
    <article className={className}>{children}</article>
  );
}
