"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { billing, type TransferInstructions } from "@/lib/billing";

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
export function TransferDestination() {
  const [data, setData] = useState<TransferInstructions | null>(null);
  const [configured, setConfigured] = useState<boolean | null>(null);

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

  if (configured === null) return null;

  if (!configured) {
    return (
      <Alert tone="warning" title="بيانات التحويل غير معلنة بعد">
        تواصل مع إدارة المنصّة لتعرف وجهة التحويل، ثم ارفع الإيصال من هنا.
      </Alert>
    );
  }

  const rows: [string, string | undefined][] = [
    ["البنك", data?.bank_name],
    ["اسم الحساب", data?.account_name],
    ["رقم الحساب", data?.account_number],
    ["الآيبان", data?.iban],
    [data?.wallet_label ?? "المحفظة", data?.wallet_number],
  ];

  return (
    <div className="rounded-xl border border-line bg-primary-soft p-4">
      <h3 className="text-sm font-bold text-ink">حوِّل إلى</h3>

      <dl className="mt-2 space-y-1">
        {rows
          .filter(([, value]) => typeof value === "string" && value !== "")
          .map(([label, value]) => (
            <div key={label} className="flex flex-wrap gap-2 text-sm">
              <dt className="text-ink-muted">{label}:</dt>
              {/*
                ⚠️ `bdi` ونصٌّ أحاديُّ العرض: الآيبان ورقمُ الحسابِ لاتينيّانِ في
                فقرةٍ عربيّة، وبلا العزلِ تُعيدُ الخوارزميّةُ ثنائيّةُ الاتّجاهِ
                ترتيبَ مقاطعِهما على الشاشةِ فيُنسَخُ رقمٌ غيرُ الذي كُتِب.
              */}
              <dd className="font-mono font-semibold text-ink">
                <bdi>{value}</bdi>
              </dd>
            </div>
          ))}
      </dl>

      {typeof data?.note === "string" && data.note !== "" && (
        <p className="mt-2 text-xs text-ink-muted">{data.note}</p>
      )}
    </div>
  );
}
