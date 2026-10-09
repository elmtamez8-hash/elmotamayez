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
  OrdersIcon,
} from "@/components/icons";
import { platformIdentity } from "@/lib/platform";
import { arabicDigits } from "@/lib/numerals";
import { BrandMark } from "@/components/ui/BrandMark";

const COLUMNS = [
  {
    title: "المنصة",
    links: [
      { href: "/teachers", label: "المدرسون", Icon: UsersIcon },
      { href: "/courses", label: "الكورسات", Icon: BookIcon },
      { href: "/store", label: "المتجر", Icon: OrdersIcon },
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
      { href: "/privacy#breach-report", label: "الإبلاغ عن تسرّب بيانات", Icon: ShieldIcon },
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

// The bottom bar is burgundy, so these are drawn in white over it — the one
// place `text-white` is earned, as the foreground of `bg-primary`.
const SOCIAL_CLASS =
  "flex h-10 w-10 items-center justify-center rounded-full border border-white/30 text-white transition duration-200 motion-reduce:transition-none";

export async function SiteFooter() {
  const { name, supportWhatsapp } = await platformIdentity();

  return (
    // relative + isolate: bg-dots paints on ::before at z-index -1, which needs a
    // stacking context of its own or it slides behind the page background.
    <footer className="bg-dots relative isolate mt-24 overflow-hidden border-t border-line bg-surface-raised print:hidden">
      {/* The wordmark again, enormous and faint in the corner — the footer's
          one bold move, and the brand's own shape rather than a decoration
          borrowed from somewhere else. */}
      <span
        aria-hidden="true"
        className="wordmark pointer-events-none absolute -bottom-16 -end-16 -z-10 h-[26rem] opacity-[0.05]"
      />
      <div className="mx-auto max-w-7xl px-4 pb-14 pt-16 sm:px-6">
        <div className="grid gap-12 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.4fr)_repeat(3,minmax(0,1fr))]">
          <div className="sm:col-span-2 lg:col-span-1">
            {/* The mark, where the name was set as text — the same one the
                header and the panel paint, from the same file. */}
            <div className="mb-5">
              <BrandMark size="xl" />
            </div>
            <p className="mb-5 max-w-sm leading-relaxed text-ink-muted">
              نربط الطلاب في العالم العربي بمدرّسين موثوقين، بحصص مباشرة ومسجّلة،
              ودرجة ثقة توضّح التزام كل مدرّس قبل أن تحجز.
            </p>
            {/*
              ⚠️ A NEWSLETTER FORM STOOD HERE, AND IT SUBSCRIBED NOBODY. No
              `onSubmit`, no route on the backend and no list anywhere — pressing
              «اشترك» reloaded the page with the address in the query string and
              the visitor believed they were signed up. Removed rather than wired:
              a mailing list is a processor, a consent record and an unsubscribe
              path, none of which exist. It comes back when they do.
            */}
          </div>

          {COLUMNS.map((column) => (
            <nav key={column.title} aria-label={column.title}>
              <h2 className="mb-5 flex items-center gap-2 text-base font-extrabold text-ink">
                <span aria-hidden="true" className="h-4 w-1 rounded-full bg-primary" />
                {column.title}
              </h2>
              <ul className="space-y-3">
                {column.links.map(({ href, label, Icon }) => (
                  <li key={href}>
                    {/* The icon nudges toward the text on hover — a small cue
                        that the whole row is one target, not two. */}
                    <Link
                      href={href}
                      className="group inline-flex items-center gap-3 text-sm font-semibold text-ink-muted transition duration-200 hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none"
                    >
                      <span
                        aria-hidden="true"
                        className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary-ink transition duration-200 group-hover:bg-primary group-hover:text-white"
                      >
                        <Icon className="h-4 w-4" />
                      </span>
                      <span className="link-underline">{label}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            </nav>
          ))}
        </div>

      </div>

      <div className="bg-squares relative isolate overflow-hidden bg-primary">
        <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-7 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
          <p className="order-3 text-sm text-white/80 lg:order-1">
            © {arabicDigits(new Date().getFullYear())} {name}. جميع الحقوق محفوظة.
          </p>

          <div className="order-1 flex flex-wrap items-center gap-2 lg:order-2">
            <span className="text-sm text-white/80">طرق الدفع:</span>
            <span className="inline-flex items-center gap-1.5 rounded-full border border-white/30 px-3 py-1 text-sm font-semibold text-white">
              <BankIcon className="h-4 w-4" />
              تحويل بنكي
            </span>
          </div>

          <ul className="order-2 flex items-center gap-3 lg:order-3">
            {supportWhatsapp !== "" && (
              <li>
                <a
                  href={`https://wa.me/${supportWhatsapp}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  aria-label="تواصل معنا عبر واتساب"
                  className={`${SOCIAL_CLASS} bg-secondary hover:-translate-y-0.5 hover:border-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:hover:translate-y-0`}
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
                    className={`${SOCIAL_CLASS} cursor-not-allowed opacity-50`}
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
                    className={`${SOCIAL_CLASS} hover:-translate-y-0.5 hover:border-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white motion-reduce:hover:translate-y-0`}
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
