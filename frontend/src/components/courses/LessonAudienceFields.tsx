"use client";

import { useEffect, useState } from "react";
import { MultiSelectField } from "@/components/ui/Field";
import { manageCohorts, type CohortOption } from "@/lib/cohorts";

/**
 * «لمن هذا العنصر» — المفتاحُ الصريحُ بيدِ المدرّس (٠٢٦ · FR-001 · FR-003).
 *
 * ⛔ **ولا يُعرَضُ إطلاقاً على كورسٍ بلا مجموعات.** كورسٌ لا يعملُ بمجموعاتٍ
 * سؤالُه «لمن هذا؟» سؤالٌ لا جوابَ له، وحقلٌ فارغٌ معطَّلٌ بجوارِ كلِّ عنصرٍ هو
 * ضجيجٌ على كلِّ كورسٍ مسجَّلٍ على المنصّة. وهذا هو الفرقُ بين «لا مجموعةَ
 * مفتوحة» و«لا مجموعةَ أصلاً» الذي يسجّلُه هذا المستودعُ في `coursesWithCohorts`.
 *
 * ⚠️ **وفارغٌ = للجميع، لا «لا أحد».** الحقلُ الفارغُ هو الحالُ الذي وُلِدَ عليه
 * كلُّ عنصرٍ على المنصّة، فالعبارةُ تحتَه تقولُ ذلكَ بالنصّ — وإلّا قرأَه
 * المدرّسُ «مخفيٌّ عن الجميع» فلم يجرؤْ على تركِه.
 */
export function LessonAudienceFields({
  courseUuid,
  value,
  onChange,
  disabled,
}: {
  courseUuid: string;
  value: string[];
  onChange: (next: string[]) => void;
  disabled?: boolean;
}) {
  const [options, setOptions] = useState<CohortOption[] | null>(null);

  useEffect(() => {
    let alive = true;

    manageCohorts
      .list(courseUuid)
      /*
        ⚠️ **الفشلُ يُخفي الحقلَ ولا يُعطِّلُ الشاشة.** هذا حقلٌ إضافيٌّ في محرّرٍ
        وظيفتُه الأولى تحريرُ المحتوى؛ فلافتةُ خطأٍ هنا تُوقِفُ حفظَ عنوانٍ لا
        علاقةَ له بالمجموعات. والمحورُ لا يتغيّرُ ما دامَ الحقلُ لم يُرسَلْ —
        `cohort_uuids` غائبٌ يعني «اتركْه كما هو» على الخادم.
      */
      .then((res) => alive && setOptions(res.data.filter((c) => c.status !== "archived")))
      .catch(() => alive && setOptions([]));

    return () => {
      alive = false;
    };
  }, [courseUuid]);

  if (options === null || options.length === 0) return null;

  return (
    <MultiSelectField
      id="lesson-audience"
      label="لمن هذا العنصر"
      value={value}
      onChange={onChange}
      disabled={disabled}
      options={options.map((cohort) => ({ value: cohort.uuid, label: cohort.name }))}
      placeholder="لكل المجموعات"
      hint="اتركه فارغاً ليظهر لكل طلاب الكورس. واختيار مجموعة يعني أنّ غيرها لن ترى العنصر أصلاً، ولن يدخل في نسبة إتمامها."
    />
  );
}
