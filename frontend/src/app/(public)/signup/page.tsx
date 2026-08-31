import type { Metadata } from "next";
import Link from "next/link";
import { AcademicCapIcon, UserPlusIcon, UsersIcon } from "@/components/icons";
import { platformName } from "@/lib/platform";

export async function generateMetadata(): Promise<Metadata> {
  const name = await platformName();

  return {
    title: `إنشاء حساب — ${name}`,
    description: "اختر صفتك: طالب أو وليّ أمر أو مدرّس.",
  };
}

/**
 * The role chooser, and the door `/login` sends a visitor with no invitation to.
 *
 * ⚠️ `/register` IS NOT THIS PAGE AND MUST NOT BE LINKED TO AS ONE. It creates a
 * role-less account and asks for none of what a student's needs — a date of birth
 * above all, which `RegisterStudent` turns into the guardian-consent gate. It is
 * the invitation and academy-founder door; sending an ordinary visitor there is
 * how a student ends up registered with no year, no region and no guardian gate,
 * silently. That is what this page exists to stop.
 *
 * It sits under `signup/layout.tsx`, so a signed-in visitor is redirected to their
 * own panel exactly as they are on the three forms below.
 */
const ROLES = [
  {
    href: "/signup/student",
    label: "طالب",
    blurb: "احجز حصصك مع مدرّس تختاره بنفسك.",
    Icon: UserPlusIcon,
  },
  {
    href: "/signup/parent",
    label: "وليّ أمر",
    blurb: "تابع حضور أبنائك وتقاريرهم من مكان واحد.",
    Icon: UsersIcon,
  },
  {
    href: "/signup/teacher",
    label: "مدرّس",
    blurb: "قدّم طلبك للانضمام بعد مراجعة أكاديمية.",
    Icon: AcademicCapIcon,
  },
] as const;

export default function SignupChooserPage() {
  return (
    <div className="mx-auto max-w-2xl px-4 py-12 sm:px-6">
      <div className="mb-8 text-center">
        <h1 className="mb-2 text-2xl font-extrabold text-ink sm:text-3xl">إنشاء حساب</h1>
        <p className="text-ink-muted">اختر صفتك، ولكلٍّ نموذجه.</p>
      </div>

      <ul className="space-y-4">
        {ROLES.map(({ href, label, blurb, Icon }) => (
          <li key={href}>
            <Link
              href={href}
              className="flex items-center gap-4 rounded-3xl border border-line bg-surface-raised p-6 transition hover:border-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              <span className="rounded-2xl bg-primary-soft p-3 text-primary-ink">
                <Icon className="h-6 w-6" />
              </span>
              <span className="text-start">
                <span className="block text-lg font-semibold text-ink">{label}</span>
                <span className="block text-sm text-ink-muted">{blurb}</span>
              </span>
            </Link>
          </li>
        ))}
      </ul>

      <p className="mt-8 text-center text-sm text-ink-muted">
        لديك حساب بالفعل؟{" "}
        <Link href="/login" className="rounded text-primary-ink underline underline-offset-4">
          سجّل الدخول
        </Link>
      </p>
    </div>
  );
}
