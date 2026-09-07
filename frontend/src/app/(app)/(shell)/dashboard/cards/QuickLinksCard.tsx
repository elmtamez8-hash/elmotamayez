"use client";

import Link from "next/link";

import { useAuth } from "@/lib/auth-context";
import { quickAccessFor } from "@/lib/panel-nav";
import { DashboardCard } from "./DashboardCard";

/**
 * الروابطُ اليوميّةُ لهذا الحساب.
 *
 * ⚠️ **مشتقّةٌ من `quickAccessFor()`، ولا قائمةَ ثانيةً تُكتَبُ هنا.** تلك الدالّةُ
 * تُرتِّبُ بالدَّورِ وتُرشِّحُ بـ`allowedNav`، فكلُّ رابطٍ تُخرِجُه رابطٌ يملكُ
 * القارئُ شاشتَه فعلاً. وقائمةٌ مكتوبةٌ بجوارِها تُقدِّمُ للطالبِ شاشةً تُجيبُ
 * `403`، وتنسى الشاشةَ الجديدةَ يومَ تُضاف — وهو العطلُ الذي دفعَ ثمنَه هذا
 * المستودعُ مرّتَينِ في القائمةِ الجانبيّةِ نفسِها.
 *
 * ولا تحميلَ ولا فشل: القائمةُ من `user` المحمَّلِ مرّةً عندَ الإقلاع، بلا طلب.
 */
export function QuickLinksCard() {
  const { user } = useAuth();
  const links = quickAccessFor(user);

  if (links.length === 0) return null;

  return (
    <DashboardCard title="روابط سريعة" href="/settings" linkLabel="الإعدادات">
      <ul className="grid grid-cols-2 gap-2">
        {links.map((link) => (
          <li key={link.href}>
            <Link
              href={link.href}
              className="flex items-center gap-2 rounded-lg border border-line p-3 text-sm text-ink hover:border-primary/40 hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              <link.Icon className="h-4 w-4 shrink-0" />
              {link.label}
            </Link>
          </li>
        ))}
      </ul>
    </DashboardCard>
  );
}
