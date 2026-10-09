import Image from "next/image";
import Link from "next/link";
import type { ComponentType, ReactNode } from "react";
import {
  AcademicCapIcon,
  AlertIcon,
  CoursesIcon,
  FamilyIcon,
  HumanReviewIcon,
  MembersIcon,
  PlayIcon,
  ProgressIcon,
  ScheduleIcon,
  SessionsIcon,
  SettlementIcon,
  TrustShieldIcon,
  UserPlusIcon,
  UsersIcon,
} from "@/components/icons";

export type SignupRole = "student" | "teacher" | "parent";

type Point = { Icon: ComponentType<{ className?: string }>; title: string; body: string };

/*
 | ⚠️ EVERY POINT IS ONE THE HOME PAGE ALREADY MAKES, word for word — its
 | TRUST/LIVE/PARENT/TEACHER feature lists, which were checked against the code
 | on 2026-10-01. A signup screen is the last place to promise something the
 | product does not do; add a point here only from those lists.
*/
const ROLES: Record<
  SignupRole,
  { href: string; tab: string; TabIcon: ComponentType<{ className?: string }>; image: string; imageAlt: string; points: Point[] }
> = {
  student: {
    href: "/signup/student",
    tab: "طالب",
    TabIcon: UserPlusIcon,
    image: "/marketplace/auth-register.webp",
    imageAlt: "",
    points: [
      { Icon: HumanReviewIcon, title: "مراجعة قبل الانضمام", body: "كل مدرّس يمرّ بمراجعة أكاديمية قبل أن يظهر في المنصة." },
      { Icon: TrustShieldIcon, title: "درجة ثقة من أداء فعلي", body: "تُحسب من الحضور والالتزام بالمواعيد والتقييم وسرعة الرد." },
      { Icon: SessionsIcon, title: "حصة تجريبية أولاً", body: "شاهد درساً مسجّلاً من شرح المدرّس مجاناً قبل أن تشترك." },
      { Icon: PlayIcon, title: "راجع بعد الحصة", body: "الكورسات المسجّلة متاحة لك تشاهدها في وقتك." },
    ],
  },
  teacher: {
    href: "/signup/teacher",
    tab: "مدرّس",
    TabIcon: AcademicCapIcon,
    image: "/marketplace/section-teachers.webp",
    imageAlt: "",
    points: [
      { Icon: SessionsIcon, title: "حصص مباشرة بجدولك", body: "فردية وجماعية، في الأوقات التي تحدّدها أنت." },
      { Icon: CoursesIcon, title: "كورسات واختبارات", body: "دروس مسجّلة، وبنك أسئلة، واختبارات تُصحَّح تلقائياً." },
      { Icon: MembersIcon, title: "مساعدون معك", body: "أضف مساعدين يتابعون طلابك ومجموعاتك." },
      { Icon: SettlementIcon, title: "مستحقات واضحة", body: "كشف بكل حصة درّستها وما يقابلها." },
    ],
  },
  parent: {
    href: "/signup/parent",
    tab: "وليّ أمر",
    TabIcon: UsersIcon,
    image: "/marketplace/section-parents.webp",
    imageAlt: "",
    points: [
      { Icon: FamilyIcon, title: "اربط حساب ابنك", body: "برمز من حسابه، ولا ترى شيئاً قبل موافقته." },
      { Icon: ScheduleIcon, title: "الحضور والمواعيد", body: "تعرف متى حصته القادمة، وهل حضر الحصة الماضية." },
      { Icon: ProgressIcon, title: "النتائج وكشف الدرجات", body: "تقدير كل فترة، بمساهمة كل مدرّس على حدة." },
      { Icon: AlertIcon, title: "المدفوعات والإنذارات", body: "ما عليه من مستحقات، وأي إنذار أكاديمي في وقته." },
    ],
  },
};

const ORDER: SignupRole[] = ["student", "teacher", "parent"];

/**
 * The frame the three registration pages share: a burgundy panel that says
 * what this kind of account gets, a switcher between the three kinds, and the
 * form in a card.
 *
 * The panel is beside the form on wide screens and above it on a phone, where
 * the photograph is dropped so the first field is reached sooner. The page's
 * one `<h1>` is in the panel.
 */
export function SignupFrame({
  role,
  title,
  subtitle,
  notice,
  children,
}: {
  role: SignupRole;
  title: string;
  subtitle: string;
  /** Anything that must sit above the form (the student's return-to-teacher note). */
  notice?: ReactNode;
  children: ReactNode;
}) {
  const current = ROLES[role];

  return (
    <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:py-12">
      <div className="grid items-start gap-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-12">
        <aside className="bg-squares relative isolate overflow-hidden rounded-3xl bg-primary text-white shadow-xl shadow-primary/20 lg:sticky lg:top-28">
          <div className="relative hidden aspect-[16/9] lg:block">
            <Image
              src={current.image}
              alt={current.imageAlt}
              fill
              sizes="(min-width: 1024px) 40vw, 0px"
              className="object-cover"
              priority
            />
          </div>

          <div className="p-6 sm:p-8">
            <h1 className="mb-3 text-balance text-3xl font-extrabold leading-tight sm:text-4xl">{title}</h1>
            <p className="mb-8 leading-relaxed text-white/80">{subtitle}</p>

            <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-1">
              {current.points.map(({ Icon, title: pointTitle, body }) => (
                <li key={pointTitle} className="group flex gap-3">
                  <span
                    aria-hidden="true"
                    className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-accent text-accent-foreground transition duration-300 ease-out group-hover:rotate-6 group-hover:scale-110 motion-reduce:transition-none motion-reduce:group-hover:rotate-0 motion-reduce:group-hover:scale-100"
                  >
                    <Icon className="h-5 w-5" />
                  </span>
                  <span>
                    <span className="block font-bold">{pointTitle}</span>
                    <span className="block text-sm leading-relaxed text-white/75">{body}</span>
                  </span>
                </li>
              ))}
            </ul>
          </div>
        </aside>

        <div className="min-w-0">
          <nav aria-label="نوع الحساب" className="mb-5">
            <ul className="grid grid-cols-3 gap-2 rounded-2xl border border-line bg-surface-raised p-1.5">
              {ORDER.map((key) => {
                const { href, tab, TabIcon } = ROLES[key];
                const active = key === role;

                return (
                  <li key={key}>
                    <Link
                      href={href}
                      aria-current={active ? "page" : undefined}
                      className={`flex items-center justify-center gap-2 rounded-xl px-2 py-2.5 text-sm font-bold transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                        active
                          ? "bg-primary text-white shadow-md shadow-primary/20"
                          : "text-ink-muted hover:bg-primary-soft hover:text-primary-ink"
                      }`}
                    >
                      <TabIcon className="h-4 w-4 shrink-0" />
                      {tab}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </nav>

          {notice}

          <div className="rounded-3xl border border-line bg-surface-raised p-6 shadow-sm sm:p-8">
            {children}
          </div>
        </div>
      </div>
    </div>
  );
}
