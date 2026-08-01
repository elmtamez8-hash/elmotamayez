import Link from "next/link";
import { PLATFORM_NAME } from "@/lib/platform";

const COLUMNS = [
  {
    title: "المنصة",
    links: [
      { href: "/teachers", label: "المدرسون" },
      { href: "/courses", label: "الكورسات" },
      { href: "/pricing", label: "الأسعار" },
      { href: "/about", label: "عن المنصة" },
    ],
  },
  {
    title: "انضم إلينا",
    links: [
      { href: "/signup/student", label: "تسجيل كطالب" },
      { href: "/signup/teacher", label: "تسجيل كمدرّس" },
      { href: "/signup/parent", label: "تسجيل كوليّ أمر" },
    ],
  },
  {
    title: "قانوني",
    links: [
      { href: "/terms", label: "الشروط والأحكام" },
      { href: "/privacy", label: "سياسة الخصوصية" },
      { href: "/refunds", label: "سياسة الاسترجاع" },
    ],
  },
];

const SOCIAL = [
  { href: "https://twitter.com", label: "إكس" },
  { href: "https://instagram.com", label: "إنستغرام" },
  { href: "https://youtube.com", label: "يوتيوب" },
];

export function SiteFooter() {
  return (
    <footer className="mt-20 border-t border-line bg-surface">
      <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6">
        <div className="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <p className="mb-3 text-xl font-extrabold text-primary">{PLATFORM_NAME}</p>
            <p className="mb-4 text-sm leading-relaxed text-ink-muted">
              نربط الطلاب في العالم العربي بمدرّسين موثوقين، بحصص مباشرة ومسجّلة.
            </p>
            <form className="flex gap-2">
              <label htmlFor="newsletter" className="sr-only">
                بريدك الإلكتروني للاشتراك في النشرة
              </label>
              <input
                id="newsletter"
                type="email"
                required
                placeholder="بريدك الإلكتروني"
                className="min-w-0 flex-1 rounded-xl border border-line bg-white px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary dark:bg-transparent"
              />
              <button
                type="submit"
                className="shrink-0 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:brightness-110"
              >
                اشترك
              </button>
            </form>
          </div>

          {COLUMNS.map((column) => (
            <nav key={column.title} aria-label={column.title}>
              <h2 className="mb-3 text-sm font-bold text-ink">{column.title}</h2>
              <ul className="space-y-2">
                {column.links.map((link) => (
                  <li key={link.href}>
                    <Link
                      href={link.href}
                      className="text-sm text-ink-muted transition hover:text-primary"
                    >
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </nav>
          ))}
        </div>

        <div className="mt-10 flex flex-col gap-4 border-t border-line pt-6 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-ink-muted">
            © {new Date().getFullYear()} {PLATFORM_NAME}. جميع الحقوق محفوظة.
          </p>

          <div className="flex items-center gap-4">
            <span className="text-sm text-ink-muted">طرق الدفع:</span>
            <span className="text-sm font-medium text-ink">تحويل بنكي</span>
          </div>

          <ul className="flex gap-4">
            {SOCIAL.map((item) => (
              <li key={item.href}>
                <a
                  href={item.href}
                  className="text-sm text-ink-muted transition hover:text-primary"
                  rel="noopener noreferrer"
                  target="_blank"
                >
                  {item.label}
                </a>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </footer>
  );
}
