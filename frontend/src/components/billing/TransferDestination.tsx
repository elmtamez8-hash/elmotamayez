"use client";

import { useEffect, useState, type ComponentType } from "react";

import { Alert } from "@/components/ui/Alert";
import { billing, type TransferInstructions } from "@/lib/billing";
import { formatMinorMoney } from "@/lib/labels";
import {
  AccountNumberIcon,
  BankIcon,
  CheckIcon,
  CopyIcon,
  IbanIcon,
  InfoIcon,
  UserIcon,
  WalletIcon,
} from "@/components/icons";

/**
 * إلى أينَ يُحوِّلُ المشتري — بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-١٦.
 *
 * ⛔ **الشاشةُ كانت تقولُ «حوِّلْ قيمة الباقة إلى حساب المنصّة» ولا تقولُ أيُّ
 * حساب**، ثمّ تطلبُ إيصالَ التحويل. قِيسَ على الإنتاج: `platform_settings` فيه
 * تسعةٌ وستّونَ صفّاً وليسَ فيها اسمُ بنكٍ ولا آيبان ولا محفظة — أي أنّ المنتَجَ
 * يطلبُ تحويلاً إلى مكانٍ غيرِ معلَن.
 *
 * ⚠️ **ولا يُرسَمُ صندوقٌ فارغٌ حينَ لا وجهةَ مضبوطة.** خاناتٌ فارغةٌ تحتَ عنوانٍ
 * تُقرَأُ بياناتٍ لم تُحمَّل، فيُعيدُ المشتري التحميلَ وينتظر. والجملةُ الصريحةُ
 * تقولُ له ماذا يفعلُ الآن — ويبقى الطلبُ ممكناً، لأنّ مَن حوّلَ بطريقةٍ اتّفقَ
 * عليها مع الإدارةِ لا يجوزُ أن يُمنَعَ من رفعِ إيصالِه.
 *
 * ⚠️ **و`configured` من الخادمِ لا يُشتَقُّ هنا**: «هل فيها رقمٌ يُحوَّلُ إليه»
 * قاعدةٌ واحدةٌ — اسمُ بنكٍ بلا رقمٍ ليسَ عنواناً — وتهجئتُها ثانيةً في
 * TypeScript هي العطبُ الذي يسجّلُه هذا المستودعُ مرّاتٍ.
 */

/** الحقولُ التي تُنسَخُ لأنّها تُلصَقُ في تطبيقِ بنك، لا تُقرَأُ فقط. */
type Row = {
  label: string;
  value: string | undefined;
  Icon: ComponentType<{ className?: string }>;
  /** رقمٌ لاتينيٌّ طويلٌ يُلصَقُ في مكانٍ آخر — يحتاجُ زرَّ نسخٍ وخطّاً أحاديّاً. */
  copyable: boolean;
};

/*
 * ⚠️ **البطاقةُ نصفانِ لا قائمةٌ واحدة، والفرقُ بينَهما فعلٌ لا تصنيف.** «بنك
 * قطر الوطني» و«منصّة المتميّز» يُقرآنِ مرّةً للتأكّدِ أنّ الوجهةَ صحيحة؛ ورقمُ
 * الحسابِ والآيبانُ والمحفظةُ تُنقَلُ حرفاً بحرفٍ إلى تطبيقِ بنك. فالثانيةُ هي
 * ما جاءَ القارئُ من أجلِه، وهي التي تُرسَمُ أكبرَ وعلى أرضيّةٍ تفصلُها.
 *
 * ولذلك `copyable` هو المفتاحُ نفسُه في الحالتَين: ما يُنسَخُ هو ما يُلصَقُ هو ما
 * يُبرَز — قاعدةٌ واحدةٌ بثلاثةِ آثار، لا ثلاثُ قوائمَ تتّفقُ حتّى أوّلِ تعديل.
 */

/**
 * زرُّ نسخٍ لحقلٍ واحد.
 *
 * ⚠️ **`navigator.clipboard` قد لا يكونُ موجوداً أصلاً**، ولا علاقةَ لذلك
 * بالصلاحيّات: خارجَ السياقِ الآمنِ لا يعرضُه المتصفّحُ إطلاقاً — وهو بالضبطِ ما
 * حدثَ مع `navigator.mediaDevices` على عنوانِ شبكةٍ محلّيّة. فالزرُّ لا يُرسَمُ
 * حينَها بدلَ أن يُرسَمَ ويرمي.
 *
 * ⚠️ **و«تمّ النسخ» لا يُعلَنُ بتبديلِ نصِّ الزرِّ وحدَه**: الزرُّ أيقونةٌ، فاسمُه
 * المقروءُ لقارئِ الشاشةِ هو `aria-label`، وهو ما يتبدّل.
 */
function CopyButton({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) return;

    const timer = setTimeout(() => setCopied(false), 2000);

    return () => clearTimeout(timer);
  }, [copied]);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
    } catch {
      // ⚠️ يُبتلَع: النصُّ ظاهرٌ على الشاشةِ ويمكنُ تحديدُه باليد، فلافتةُ خطأٍ
      // هنا تُقلِقُ عن شيءٍ لم يُفقَدْ أصلاً.
    }
  };

  return (
    <button
      type="button"
      onClick={copy}
      aria-label={copied ? `نُسخ ${label}` : `انسخ ${label}`}
      className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border transition duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none ${
        copied
          ? "border-secondary/40 bg-secondary/15 text-secondary-ink"
          : "border-line bg-surface text-ink-muted hover:border-primary hover:text-primary-ink"
      }`}
    >
      {copied ? <CheckIcon className="h-4 w-4" /> : <CopyIcon className="h-4 w-4" />}
    </button>
  );
}

/** The sub-line under «حوِّل إلى» on `/orders`, where the upload is in the table. */
export const TABLE_HINT = "ثم ارفع صورة الإيصال من الجدول بالأسفل.";

export function TransferDestination({
  amount,
  hint = TABLE_HINT,
}: {
  /**
   * The exact sum to transfer, printed as the card's first line.
   *
   * `undefined` prints no line at all (`/orders` carries its own total above).
   * ⚠️ `null` is a DIFFERENT answer — «a plan will set the figure, and none is
   * chosen yet» — and says so, rather than a transfer box that names no figure
   * on the screen asking for the transfer (reported 2026-09-26).
   */
  amount?: { minor: number; currency: string } | null;
  /**
   * The line under «حوِّل إلى». `null` drops it.
   *
   * ⚠️ IT IS A CLAIM ABOUT THE PAGE AROUND THE CARD, so the page decides it:
   * «ارفع صورة الإيصال من الجدول» was read on `/subscribe`, which has no table,
   * and on `/orders` beside a receipt already uploaded (reported 2026-09-26).
   */
  hint?: string | null;
} = {}) {
  const [data, setData] = useState<TransferInstructions | null>(null);
  const [configured, setConfigured] = useState<boolean | null>(null);
  const [canCopy, setCanCopy] = useState(false);

  useEffect(() => {
    billing
      .transferInstructions()
      .then((answer) => {
        setData(answer.data);
        setConfigured(answer.configured);
      })
      /*
        ⚠️ يُبتلَعُ عمداً: هذه بياناتٌ مُساعِدةٌ فوقَ نموذجٍ يعملُ، ولافتةُ خطأٍ
        هنا تُوحي بأنّ الإرسالَ نفسَه متعذّر. و`configured === null` يعني «لم
        نعرفْ بعد» فلا يُرسَمُ شيء — وهو أصدقُ من ادّعاءِ أنّه غيرُ مضبوط.
      */
      .catch(() => setConfigured(null));
  }, []);

  /*
    ⚠️ يُسألُ في `useEffect` لا أثناءَ الرسم. الخادمُ لا `navigator` عندَه، وقراءتُه
    في جسمِ المكوّنِ تجعلُ أوّلَ رسمٍ في المتصفّحِ يخالفُ ما جاءَ من الخادمِ فيشتكي
    React من عدمِ التطابق.
  */
  useEffect(() => {
    setCanCopy(typeof navigator !== "undefined" && typeof navigator.clipboard?.writeText === "function");
  }, []);

  if (configured === null) return null;

  if (!configured) {
    return (
      <Alert tone="warning" title="بيانات التحويل غير معلنة بعد">
        تواصل مع إدارة المنصّة لتعرف وجهة التحويل، ثم ارفع الإيصال من هنا.
        {amount != null && (
          <span className="mt-1 block">
            المبلغ المطلوب تحويله:{" "}
            <bdi className="font-bold">{formatMinorMoney(amount.minor, amount.currency)}</bdi>
          </span>
        )}
      </Alert>
    );
  }

  const rows: Row[] = [
    { label: "البنك", value: data?.bank_name, Icon: BankIcon, copyable: false },
    { label: "اسم الحساب", value: data?.account_name, Icon: UserIcon, copyable: false },
    { label: "رقم الحساب", value: data?.account_number, Icon: AccountNumberIcon, copyable: true },
    { label: "الآيبان", value: data?.iban, Icon: IbanIcon, copyable: true },
    {
      label: data?.wallet_label ?? "المحفظة",
      value: data?.wallet_number,
      Icon: WalletIcon,
      copyable: true,
    },
  ];

  const shown = rows.filter((row): row is Row & { value: string } =>
    typeof row.value === "string" && row.value !== "",
  );

  return (
    <div className="animate-float-in overflow-hidden rounded-2xl border border-line bg-surface-raised">
      <div className="flex items-center gap-3 border-b border-line bg-primary-soft px-4 py-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary text-white">
          <BankIcon className="h-5 w-5" />
        </span>
        <div className="flex flex-col items-start">
          <h3 className="text-sm font-bold text-ink">حوِّل إلى</h3>
          {hint !== null && hint !== "" && <p className="text-xs text-ink-muted">{hint}</p>}
        </div>
      </div>

      {/* The figure the bank app will ask for, before the account it goes to. */}
      {amount !== undefined && (
        <div
          role="group"
          aria-label="المبلغ المطلوب تحويله"
          className="flex flex-wrap items-center justify-between gap-2 border-b border-line bg-accent/10 px-4 py-3"
        >
          <span className="text-xs text-ink-muted">المبلغ المطلوب تحويله</span>
          {amount === null ? (
            <span className="text-xs text-ink-muted">اختر الباقة أولاً لترى المبلغ.</span>
          ) : (
            <bdi className="text-lg font-bold tabular-nums text-ink">
              {formatMinorMoney(amount.minor, amount.currency)}
            </bdi>
          )}
        </div>
      )}

      {/*
        ⚠️ `dl` لا جدولٌ ولا قائمةُ فقرات: هذه أزواجُ «مصطلحٍ وقيمتِه» بنصِّها،
        وقارئُ الشاشةِ يربطُ الاسمَ بقيمتِه من العنصرِ نفسِه بلا سمةٍ إضافيّة.
      */}
      {/*
        ⚠️ **شبكةٌ لا قائمةٌ رأسيّة، لأنّ البطاقةَ تأخذُ العرضَ كلَّه الآن.** قائمةٌ
        من خمسةِ سطورٍ عبرَ ١٢٠٠ بكسل تتركُ زرَّ النسخِ على بُعدِ شاشةٍ كاملةٍ من
        الرقمِ الذي ينسخُه — مساحةٌ ميّتةٌ بينَ شيئَين ينتميانِ لبعضِهما. وكلُّ
        حقلٍ في بطاقتِه: الزرُّ بجوارِ رقمِه دائماً، مهما اتّسعتِ الشاشة.

        `dl` ما زالَ هو الوعاء: هذه أزواجُ «مصطلحٍ وقيمتِه» بنصِّها، وقارئُ الشاشةِ
        يربطُ الاسمَ بقيمتِه من العنصرِ نفسِه بلا سمةٍ إضافيّة.
      */}
      <dl className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
        {shown.map(({ label, value, Icon, copyable }) => (
          <div
            key={label}
            /* ⚠️ الأرضيّةُ والأيقونةُ المصمتةُ هما الفاصلُ بينَ نصفَي البطاقة، لا
               عنوانٌ ثالثٌ يقولُ «هذا ما تنسخُه»: ما يُنسَخُ هو ما يُلصَقُ هو ما
               يُبرَز، قاعدةٌ واحدةٌ بثلاثةِ آثار. */
            className={`flex items-center gap-3 rounded-xl border p-3 ${
              copyable ? "border-primary/25 bg-surface" : "border-line"
            }`}
          >
            <span
              className={`flex shrink-0 items-center justify-center rounded-lg ${
                copyable
                  ? "h-9 w-9 bg-primary text-white"
                  : "h-9 w-9 bg-primary-soft text-primary-ink"
              }`}
            >
              <Icon className="h-4 w-4" />
            </span>

            <div className="flex min-w-0 flex-1 flex-col items-start">
              <dt className="text-xs text-ink-muted">{label}</dt>
              {/*
                ⚠️ `bdi` ونصٌّ أحاديُّ العرض: الآيبان ورقمُ الحسابِ لاتينيّانِ في
                فقرةٍ عربيّة، وبلا العزلِ تُعيدُ الخوارزميّةُ ثنائيّةُ الاتّجاهِ
                ترتيبَ مقاطعِهما على الشاشةِ فيُنسَخُ رقمٌ غيرُ الذي كُتِب.

                ⚠️ و`break-all`: الآيبانُ تسعةٌ وعشرونَ محرفاً بلا مسافة، وعمودُ
                الشبكةِ أضيقُ منه — فبلا كسرٍ يمدُّ بطاقتَه ويكسرُ الصفَّ كلَّه.
              */}
              <dd
                className={`w-full font-semibold text-ink ${
                  copyable ? "break-all font-mono text-[0.95rem] tracking-wide" : "text-sm"
                }`}
              >
                <bdi>{value}</bdi>
              </dd>
            </div>

            {copyable && canCopy && <CopyButton label={label} value={value} />}
          </div>
        ))}
      </dl>

      {typeof data?.note === "string" && data.note !== "" && (
        <p className="flex items-start gap-2 border-t border-line bg-accent/10 px-4 py-2.5 text-xs text-ink">
          <InfoIcon className="mt-px h-4 w-4 shrink-0 text-ink-muted" />
          {data.note}
        </p>
      )}
    </div>
  );
}
