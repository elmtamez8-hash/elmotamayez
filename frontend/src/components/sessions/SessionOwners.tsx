import type { ClassSession } from "@/lib/class-sessions";

/**
 * الكورسُ ومَن يُدرّسه، سطراً واحداً تحتَ عنوانِ الحصّة.
 *
 * ⚠️ مكوّنٌ واحدٌ لا سطرانِ متطابقان: البطاقةُ والعدّادُ يعرضانِ الحقيقةَ نفسَها،
 * وتهجئتانِ لسؤالٍ واحدٍ تفترقانِ عندَ أوّلِ تعديل — وهو العطبُ المسجَّلُ في
 * `CLAUDE.md` ستَّ مرّات.
 *
 * ⚠️ ولا يُرسَمُ مفتاحٌ غائب. `teacher_name` يُرسَلُ بـ`whenLoaded`، فغيابُه يعني
 * «لم يُطلَب» لا «بلا مدرّس» — وتقويمُ المدرّسِ نفسِه لا يحتاجُ اسمَه. وحينَ
 * يغيبُ الاثنانِ لا يبقى وسمٌ فارغٌ يأخذُ ارتفاعاً بلا محتوى.
 */
export function SessionOwners({
  session,
  className = "",
}: {
  session: ClassSession;
  className?: string;
}) {
  const parts = [session.course?.title, session.teacher_name].filter(
    (part): part is string => typeof part === "string" && part.length > 0,
  );

  if (parts.length === 0) return null;

  return (
    <p className={`text-sm text-ink-muted ${className}`.trim()}>
      {parts.map((part, index) => (
        <span key={part}>
          {index > 0 && (
            // فاصلٌ مرئيٌّ ومخفيٌّ عن القارئ الآلي: قارئُ الشاشةِ يقرأُ الاسمينِ
            // متتاليينِ، ولا يقولُ «نقطة» بينهما.
            <span aria-hidden className="mx-2 text-line">
              ·
            </span>
          )}
          <bdi>{part}</bdi>
        </span>
      ))}
    </p>
  );
}
