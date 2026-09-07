"use client";

import { SelectField } from "@/components/ui/Field";
import type { GuardianRelation } from "@/lib/notifications";

/**
 * ابنٌ يمكنُ سؤالُ الخادمِ عنه — رابطُ أسرةٍ **نَشِطٌ ويحملُ حساباً**.
 *
 * ⚠️ الشرطانِ معاً، وكلٌّ منهما يقيسُ عطلاً مختلفاً:
 *
 * `student_uuid` هو ما تُرسِلُه كلُّ بطاقةٍ في `?student=`. ورابطٌ بالاسمِ وحدَه
 * — «ابنٌ لم يُسجِّلْ بعد» وهو الشكلُ الافتراضيُّ في `/family` — لا معرَّفَ له
 * إطلاقاً، فصفٌّ باسمِه في المُبدِّلِ صفٌّ يفشلُ عندَ الضغطِ عليه (سيناريو ٥).
 *
 * و`status === "active"` لأنّ `GuardianDirectory::childrenOf()` يُرشِّحُ الروابطَ
 * النشِطةَ وحدَها: رابطٌ ملغًى أو معلَّقٌ معروضٌ هنا هو ابنٌ **كلُّ** بطاقاتِه
 * تُجيبُ `403` — رفضٌ صحيحٌ من الخادمِ عن سؤالٍ ما كان ينبغي أن يُطرَح.
 */
export function selectableChildren(relations: GuardianRelation[]): GuardianRelation[] {
  return relations
    .filter((relation) => relation.status === "active" && Boolean(relation.student_uuid))
    .sort((a, b) => a.student_name.localeCompare(b.student_name, "ar"));
}

/**
 * مَن مِن الأبناءِ تُعرَضُ بياناتُه (٠٢٩ · `US3` · سيناريو ٢).
 *
 * ⚠️ لا يظهرُ لابنٍ واحد: قائمةُ اختيارٍ بخيارٍ وحيدٍ سؤالٌ لا جوابَ له غيرُ
 * جوابِه. ولا يُحفَظُ الاختيارُ بينَ الزيارات — «آخرُ ابنٍ نظرتِ إليه» تفضيلٌ لم
 * يطلبْه أحد، وحفظُه يعني أنّ أمّاً تفتحُ اللوحةَ فترى ابنَها الآخرَ لسببٍ لا
 * تراهُ على الشاشة.
 */
export function ChildSwitcher({
  options,
  value,
  onChange,
}: {
  options: GuardianRelation[];
  value: string;
  onChange: (studentUuid: string) => void;
}) {
  // ⚠️ `options` ولا `children`: الاسمُ الثاني محجوزٌ في React لمحتوى العنصر،
  // فخاصّيّةٌ بهذا الاسمِ تقرأُ عندَ الاستدعاءِ كأنّها شيءٌ آخرُ تماماً.
  if (options.length < 2) return null;

  return (
    <div className="max-w-xs">
      <SelectField
        id="dashboard-child"
        label="الابن المعروضة بياناته"
        value={value}
        onChange={onChange}
        options={options.map((relation) => ({
          value: relation.student_uuid as string,
          label: relation.student_name,
        }))}
      />
    </div>
  );
}
