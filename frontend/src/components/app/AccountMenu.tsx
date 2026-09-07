"use client";

import Link from "next/link";
import { useEffect, useRef, useState, type ComponentType } from "react";

import { Avatar } from "@/components/ui/Avatar";
import {
  ChevronDownIcon,
  HomeIcon as PanelIcon,
  LogoutIcon,
  SettingsIcon,
  SiteIcon,
  UserIcon,
  type IconProps,
} from "@/components/icons";

/**
 * قائمةُ الحسابِ في الترويسة: الصورةُ والاسمُ الأوّل، وتحتَهما وصولٌ سريع.
 *
 * ⚠️ **الروابطُ السريعةُ تصلُ مُرشَّحةً ولا تُبنى هنا.** الشريطُ الجانبيُّ يمرّرُ
 * ما نجا من `allowed()` — الصلاحيّةَ والجمهورَ معاً — فقائمةٌ ثانيةٌ مكتوبةٌ في
 * هذا الملفِّ كانت ستعرضُ «واجباتي» لمدرّسٍ و«لوحة الصدارة» لموظّفٍ ماليّ، ثمّ
 * تفترقُ عن الشريطِ عندَ أوّلِ بندٍ يُضاف. عائلةُ `ListLeaderboardScopes` نفسُها:
 * منتقٍ يُشتَقُّ من قاعدةِ صاحبِ القرارِ لا يُجمَّعُ بجانبِها.
 *
 * ⚠️ **والصورةُ من `useAuth()` عندَ المُنادي لا من جلبٍ خاصّ.** فهي تتحدّثُ لحظةَ
 * حفظِها في `‎/settings/profile` (`refreshUser()`)، وجلبٌ ثانٍ هنا كانَ سيُبقيها
 * قديمةً — وهو بعينِه ما بلّغَ عنه المستخدِم.
 */
export function AccountMenu({
  name,
  firstName,
  photoUrl,
  quickLinks,
  panelHref,
  onLogout,
}: {
  name: string;
  firstName: string;
  photoUrl: string | null;
  quickLinks: Array<{ href: string; label: string; Icon: ComponentType<IconProps> }>;
  /**
   * رابطُ اللوحةِ — للترويسةِ العامّةِ وحدَها.
   *
   * داخلَ اللوحةِ يكونُ رابطاً إلى المكانِ الذي يقفُ فيهِ قارئُهُ أصلاً،
   * وهو الشكلُ الذي يجعلُ الطالبَ يضغطُ مرّتَينِ ليصلَ إلى حيثُ هو.
   */
  panelHref?: string;
  onLogout: () => void;
}) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  /*
   * ⚠️ الإغلاقُ بالضغطِ خارجَها وبـ`Escape` معاً، وكلاهما مطلوب. قائمةٌ تبقى
   * مفتوحةً بعدَ أن ينصرفَ عنها صاحبُها تعترضُ ما تحتَها، ولوحةُ المفاتيحِ بلا
   * `Escape` تحبسُ من يتنقّلُ بها في قائمةٍ لا مخرجَ منها إلّا الفأرة.
   *
   * `pointerdown` لا `click`: الضغطةُ التي تفتحُ شيئاً آخرَ تبدأُ بها، فالإغلاقُ
   * عندَ `click` يقعُ بعدَ أن تكونَ القائمةُ قد ابتلعتِ الضغطةَ الأولى.
   */
  useEffect(() => {
    if (!open) return;

    const away = (event: PointerEvent) => {
      if (!box.current?.contains(event.target as Node)) setOpen(false);
    };
    const escape = (event: KeyboardEvent) => {
      if (event.key === "Escape") setOpen(false);
    };

    document.addEventListener("pointerdown", away);
    document.addEventListener("keydown", escape);

    return () => {
      document.removeEventListener("pointerdown", away);
      document.removeEventListener("keydown", escape);
    };
  }, [open]);

  const item =
    "flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink transition-colors hover:bg-primary-soft hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary";

  return (
    <div className="relative" ref={box}>
      <button
        type="button"
        onClick={() => setOpen((was) => !was)}
        aria-expanded={open}
        aria-haspopup="menu"
        /* الاسمُ الكاملُ للقارئِ الآليّ، والأوّلُ وحدَه على الشاشة: الترويسةُ صفٌّ
           واحدٌ وقد يكونُ الاسمُ ثلاثيّاً. */
        aria-label={`حساب ${name}`}
        className="flex items-center gap-2 rounded-full py-1 pe-2 ps-1 text-sm text-ink transition-colors hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        <Avatar url={photoUrl} name={name} size="sm" />
        <span className="hidden max-w-24 truncate font-medium sm:inline">{firstName}</span>
        <ChevronDownIcon />
      </button>

      {open && (
        <div
          role="menu"
          /* ⚠️ `end-0` لا `right-0`: الصفحةُ RTL، والقائمةُ تتدلّى من حافّةِ
             الزرِّ التي تليها في اتّجاهِ القراءة. */
          className="absolute end-0 top-full z-20 mt-2 w-60 rounded-xl border border-line bg-surface-raised p-1.5 shadow-lg"
        >
          {/*
            ⚠️ **أوّلَ بندٍ في القائمة، وبالاسمِ لا بكلمةِ «ملفّي»** (طلبُ
            ٢٠٢٦-٠٩-٠٧). البندُ كانَ يقولُ «ملفّي» تحتَ «وصول سريع» و«لوحتي»،
            فيقرؤُه صاحبُه رابطاً كسائرِ الروابط؛ وهو في الحقيقةِ **ترويسةُ
            القائمة**: مَن أنت، وأينَ تُغيِّرُ بياناتِك. فالاسمُ الكاملُ هنا يقولُ
            «هذا حسابُك» في موضعٍ يُقرَأُ أوّلاً.

            ⚠️ و`UserIcon` لا `Avatar`: الصورةُ في الزرِّ فوقَه مباشرةً، وتكرارُها
            على بُعدِ بضعةِ بكسلاتٍ يقولُ الشيءَ مرّتَين. والرمزُ يبقى ثابتاً
            لحسابٍ بلا صورة، حيثُ كانَ `Avatar` يرسمُ حرفاً أوّلَ يُقرَأُ نصّاً
            ثانياً بجوارِ الاسم.
          */}
          <Link
            href="/settings/profile"
            role="menuitem"
            className={item}
            onClick={() => setOpen(false)}
          >
            <UserIcon />
            <span className="truncate font-medium">{name}</span>
          </Link>

          <div className="my-1.5 border-t border-line" />

          {quickLinks.length > 0 && (
            <>
              <p className="px-3 pb-1 pt-2 text-xs font-semibold tracking-wide text-ink-muted">
                وصول سريع
              </p>
              {quickLinks.map(({ href, label, Icon }) => (
                <Link key={href} href={href} role="menuitem" className={item} onClick={() => setOpen(false)}>
                  <Icon />
                  {label}
                </Link>
              ))}
              <div className="my-1.5 border-t border-line" />
            </>
          )}

          {panelHref !== undefined && (
            <Link href={panelHref} role="menuitem" className={item} onClick={() => setOpen(false)}>
              <PanelIcon />
              لوحتي
            </Link>
          )}

          <Link href="/settings" role="menuitem" className={item} onClick={() => setOpen(false)}>
            <SettingsIcon />
            الإعدادات
          </Link>
          <Link href="/" role="menuitem" className={item} onClick={() => setOpen(false)}>
            <SiteIcon />
            الصفحة الرئيسية
          </Link>

          <div className="my-1.5 border-t border-line" />

          <button type="button" role="menuitem" onClick={onLogout} className={`${item} w-full`}>
            <LogoutIcon />
            تسجيل الخروج
          </button>
        </div>
      )}
    </div>
  );
}
