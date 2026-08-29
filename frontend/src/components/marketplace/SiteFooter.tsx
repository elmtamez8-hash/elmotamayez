import Link from "next/link";
import {
  AcademicCapIcon,
  BankIcon,
  BookIcon,
  DocumentIcon,
  InfoIcon,
  InstagramIcon,
  RefundIcon,
  ShieldIcon,
  TagIcon,
  UserPlusIcon,
  UsersIcon,
  WhatsAppIcon,
  XIcon,
  YouTubeIcon,
} from "@/components/icons";
import { PLATFORM_NAME, SUPPORT_WHATSAPP } from "@/lib/platform";

const COLUMNS = [
  {
    title: "المنصة",
    links: [
      { href: "/teachers", label: "المدرسون", Icon: UsersIcon },
      { href: "/courses", label: "الكورسات", Icon: BookIcon },
      { href: "/blog", label: "المدوّنة", Icon: DocumentIcon },
      { href: "/pricing", label: "الأسعار", Icon: TagIcon },
      { href: "/about", label: "عن المنصة", Icon: InfoIcon },
    ],
  },
  {
    title: "انضم إلينا",
    links: [
      { href: "/signup/student", label: "تسجيل كطالب", Icon: UserPlusIcon },
      { href: "/signup/teacher", label: "تسجيل كمدرّس", Icon: AcademicCapIcon },
      { href: "/signup/parent", label: "تسجيل كوليّ أمر", Icon: UsersIcon },
    ],
  },
  {
    title: "قانوني",
    links: [
      { href: "/terms", label: "الشروط والأحكام", Icon: DocumentIcon },
      { href: "/privacy", label: "سياسة الخصوصية", Icon: ShieldIcon },
      { href: "/refunds", label: "سياسة الاسترجاع", Icon: RefundIcon },
    ],
  },
];

// Placeholders until the real accounts exist. They are marked with
// aria-disabled and no href rather than pointing at the platforms' home pages —
// a social icon that opens twitter.com is a broken promise, not a link.
const SOCIAL = [
  { href: null, label: "إكس", Icon: XIcon },
  { href: null, label: "إنستغرام", Icon: InstagramIcon },
  { href: null, label: "يوتيوب", Icon: YouTubeIcon },
];

const SOCIAL_CLASS =
  "flex h-9 w-9 items-center justify-center rounded-full border border-line transition duration-200 motion-reduce:transition-none";

export function SiteFooter() {
  return (
    // relative + isolate: bg-dots paints on ::before at z-index -1, which needs a
    // stacking context of its own or it slides behind the page background.
    <footer className="bg-dots relative isolate mt-20 overflow-hidden border-t border-line bg-surface">
      <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6">
        <div className="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
          <div className="sm:col-span-2 lg:col-span-1">
            <p className="mb-3 text-xl font-extrabold text-primary-ink">
              {PLATFORM_NAME}
            </p>
            <p className="mb-5 max-w-sm text-sm leading-relaxed text-ink-muted">
              نربط الطلاب في العالم العربي بمدرّسين موثوقين، بحصص مباشرة ومسجّلة،
              ودرجة ثقة توضّح التزام كل مدرّس قبل أن تحجز.
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
                className="min-w-0 flex-1 rounded-xl border border-line bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
              />
              <button
                type="submit"
                className="shrink-0 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white transition duration-200 hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
              >
                اشترك
              </button>
            </form>
          </div>

          {COLUMNS.map((column) => (
            <nav key={column.title} aria-label={column.title}>
              <h2 className="mb-4 text-sm font-bold text-ink">{column.title}</h2>
              <ul className="space-y-2.5">
                {column.links.map(({ href, label, Icon }) => (
                  <li key={href}>
                    {/* The icon nudges toward the text on hover — a small cue
                        that the whole row is one target, not two. */}
                    <Link
                      href={href}
                      className="group inline-flex items-center gap-2 text-sm text-ink-muted transition duration-200 hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
                    >
                      <Icon className="h-4 w-4 shrink-0 text-ink-muted transition duration-200 group-hover:-translate-x-0.5 group-hover:text-primary-ink motion-reduce:transition-none motion-reduce:group-hover:translate-x-0 rtl:group-hover:translate-x-0.5" />
                      <span className="link-underline">{label}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </nav>
          ))}
        </div>

        <div className="mt-12 flex flex-col gap-6 border-t border-line pt-8 lg:flex-row lg:items-center lg:justify-between">
          <p className="order-3 text-sm text-ink-muted lg:order-1">
            © {new Date().getFullYear()} {PLATFORM_NAME}. جميع الحقوق محفوظة.
          </p>

          <div className="order-1 flex flex-wrap items-center gap-2 lg:order-2">
            <span className="text-sm text-ink-muted">طرق الدفع:</span>
            <span className="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1 text-sm font-medium text-ink">
              <BankIcon className="h-4 w-4 text-primary-ink" />
              تحويل بنكي
            </span>
          </div>

          <ul className="order-2 flex items-center gap-3 lg:order-3">
            {SUPPORT_WHATSAPP !== "" && (
              <li>
                <a
                  href={`https://wa.me/${SUPPORT_WHATSAPP}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  aria-label="تواصل معنا عبر واتساب"
                  className={`${SOCIAL_CLASS} border-secondary/40 text-secondary-ink hover:-translate-y-0.5 hover:bg-secondary/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary motion-reduce:hover:translate-y-0`}
                >
                  <WhatsAppIcon className="h-5 w-5" />
                </a>
              </li>
            )}

            {SOCIAL.map(({ href, label, Icon }) => (
              <li key={label}>
                {href === null ? (
                  // Text in a sr-only span, not aria-label: a bare <span> has no
                  // implicit role, and ARIA prohibits naming an element that
                  // cannot be named — axe flags it as aria-prohibited-attr.
                  <span
                    title={`${label} — قريباً`}
                    className={`${SOCIAL_CLASS} cursor-not-allowed text-ink-muted opacity-60`}
                  >
                    <Icon className="h-5 w-5" />
                    <span className="sr-only">{label} — قريباً</span>
                  </span>
                ) : (
                  <a
                    href={href}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={label}
                    className={`${SOCIAL_CLASS} text-ink-muted hover:-translate-y-0.5 hover:border-primary hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:hover:translate-y-0`}
                  >
                    <Icon className="h-5 w-5" />
                  </a>
                )}
              </li>
            ))}
          </ul>
        </div>
      </div>
    </footer>
  );
}
