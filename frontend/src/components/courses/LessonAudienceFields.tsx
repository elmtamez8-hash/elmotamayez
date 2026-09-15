"use client";

import { useEffect, useState } from "react";
import { MultiSelectField, SelectField } from "@/components/ui/Field";
import { classSessions, type ClassSession } from "@/lib/class-sessions";
import { manageCohorts, type CohortOption } from "@/lib/cohorts";
import { formatSessionTime } from "@/lib/session-format";

/**
 * «لمن هذا العنصرُ ومتى يظهر» — المفتاحانِ الصريحانِ بيدِ المدرّس
 * (٠٢٦ · FR-001 · FR-003 · FR-006).
 *
 * ⛔ **ولا يُعرَضُ محورٌ لا جوابَ له.** كورسٌ لا يعملُ بمجموعاتٍ سؤالُه «لمن
 * هذا؟» سؤالٌ بلا معنى، وكورسٌ بلا حصصٍ حيّةٍ كذلك في «متى يظهر» — وحقلٌ فارغٌ
 * معطَّلٌ بجوارِ كلِّ عنصرٍ ضجيجٌ على كلِّ كورسٍ مسجَّلٍ على المنصّة. فكلُّ
 * محورٍ يُعرَضُ بقائمتِه، والمكوّنُ كلُّه يختفي إن خلَتا معاً.
 *
 * ⚠️ **وفارغٌ = للجميع، لا «لا أحد»؛ و«يظهر الآن» = بلا ربط، لا «مخفيّ».**
 * الحالانِ هما ما وُلِدَ عليه كلُّ عنصرٍ على المنصّة، والعبارةُ تحتَ كلِّ حقلٍ
 * تقولُ ذلكَ بالنصّ — وإلّا قرأَهما المدرّسُ «مخفيٌّ عن الجميع» فلم يجرؤْ على
 * تركِهما.
 */
export function LessonAudienceFields({
  courseUuid,
  cohortUuids,
  releaseSessionUuid,
  onChangeCohorts,
  onChangeRelease,
  disabled,
}: {
  courseUuid: string;
  cohortUuids: string[];
  releaseSessionUuid: string | null;
  onChangeCohorts: (next: string[]) => void;
  /** `null` تعني فكَّ الربط — «يظهر الآن»، وهو مخرجُ FR-008. */
  onChangeRelease: (next: string | null) => void;
  disabled?: boolean;
}) {
  const [cohorts, setCohorts] = useState<CohortOption[] | null>(null);
  const [sessions, setSessions] = useState<ClassSession[] | null>(null);

  useEffect(() => {
    let alive = true;

    /*
      ⚠️ **الفشلُ يُخفي الحقلَ ولا يُعطِّلُ الشاشة.** هذانِ حقلانِ إضافيّانِ في
      محرّرٍ وظيفتُه الأولى تحريرُ المحتوى؛ فلافتةُ خطأٍ هنا تُوقِفُ حفظَ عنوانٍ
      لا علاقةَ له بالمجموعاتِ ولا بالحصص. والمحورُ لا يتغيّرُ ما دامَ الحقلُ لم
      يُرسَلْ — الحقلُ الغائبُ يعني «اتركْه كما هو» على الخادم.
    */
    manageCohorts
      .list(courseUuid)
      .then((res) => alive && setCohorts(res.data.filter((c) => c.status !== "archived")))
      .catch(() => alive && setCohorts([]));

    /*
      ⚠️ **`order: "desc"` عمداً**: القائمةُ محدودةٌ بخمسينَ على الخادم، فالصعودُ
      يُرجِعُ «أقدمَ خمسين» — أوّلَ أسبوعٍ في عمرِ الكورس — ولا يُرجِعُ الحصّةَ
      القادمةَ التي يربطُ بها المدرّسُ ورقتَه الآن.
    */
    classSessions
      .list({ course: courseUuid, order: "desc" })
      .then((res) => alive && setSessions(res.data))
      .catch(() => alive && setSessions([]));

    return () => {
      alive = false;
    };
  }, [courseUuid]);

  if (cohorts === null || sessions === null) return null;

  /*
    ⚠️ **والملغاةُ تُحذَفُ من القائمة.** حصّةٌ لن تُعقَدَ أبداً مُفرَجٌ عنها
    بالفعل (FR-008)، فربطُ عنصرٍ بها هو «يظهر الآن» يلبسُ اسمَ حصّة — تعليمةٌ
    تقرأُ ما لا تفعل.
  */
  const options = sessions
    .filter((session) => session.status !== "cancelled")
    .map((session) => ({
      value: session.uuid,
      label: `${session.title} — ${formatSessionTime(session.starts_at, session.timezone)}`,
    }));

  /*
    ⚠️ **الربطُ القائمُ يُعرَضُ ولو لم يكنْ في الخمسين.** بدونَه يرسمُ الحقلُ
    «يظهر الآن» لعنصرٍ مقفولٍ فعلاً — أي أنّ الأداةَ الوحيدةَ التي تُظهِرُ
    الحالَ تكذبُ فيه، ويبقى المدرّسُ بلا طريقةٍ لفكِّ الربط.
  */
  if (releaseSessionUuid !== null && !options.some((o) => o.value === releaseSessionUuid)) {
    options.unshift({ value: releaseSessionUuid, label: "حصّة مرتبطة" });
  }

  if (cohorts.length === 0 && options.length === 0) return null;

  return (
    <>
      {cohorts.length > 0 && (
        <MultiSelectField
          id="lesson-audience"
          label="لمن هذا العنصر"
          value={cohortUuids}
          onChange={onChangeCohorts}
          disabled={disabled}
          options={cohorts.map((cohort) => ({ value: cohort.uuid, label: cohort.name }))}
          placeholder="لكل المجموعات"
          hint="اتركه فارغاً ليظهر لكل طلاب الكورس. واختيار مجموعة يعني أنّ غيرها لن ترى العنصر أصلاً، ولن يدخل في نسبة إتمامها."
        />
      )}

      {options.length > 0 && (
        <SelectField
          id="lesson-release"
          label="متى يظهر هذا العنصر"
          value={releaseSessionUuid ?? ""}
          onChange={(next) => onChangeRelease(next === "" ? null : next)}
          disabled={disabled}
          options={options}
          placeholder="يظهر الآن"
          hint="اربطه بحصة ليظهر بعد انتهائها. واختيار «يظهر الآن» يفكّ الربط فيظهر فوراً — وهو المخرج لو تأجّلت الحصة. والعنصر المربوط لا يدخل في نسبة الإتمام."
        />
      )}
    </>
  );
}
