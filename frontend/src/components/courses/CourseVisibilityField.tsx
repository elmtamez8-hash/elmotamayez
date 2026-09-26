import { RadioField } from "@/components/ui/Field";

export type TeacherCourseVisibility = "public" | "private";

/**
 * «ظهور الكورس» — عامٌّ أو خاصّ، والقرارُ للمدرّس (قرارُ المالك 2026-09-26).
 *
 * ⛔ كان كلُّ كورسٍ ينشئُه المدرّسُ «خاصّاً» ولا شاشةَ تكتبُ الحقل، فكلُّ كورسٍ
 * ينشرُه يُجيبُ «غير موجود» على صفحتِه العامّةِ حتى يقلبَه موظّف. الآن عامٌّ
 * افتراضاً، وهذا الحقلُ على صفحتَي الإنشاءِ والتعديلِ كلتَيهما — مكوّنٌ واحدٌ كي
 * لا تكتبَ الشاشتانِ الجملتَينِ مرّتين.
 *
 * ⚠️ خياران لا ثلاثة: `hidden` قيمةُ المنصّةِ والخادمُ يرفضُها من المدرّس.
 */
export function CourseVisibilityField({
  value,
  onChange,
  error,
}: {
  value: TeacherCourseVisibility;
  onChange: (value: TeacherCourseVisibility) => void;
  error?: string;
}) {
  return (
    <fieldset className="space-y-2">
      <legend className="mb-1 text-sm font-medium text-ink">ظهور الكورس</legend>
      <RadioField
        id="visibility_public"
        name="visibility"
        label="عام — يظهر في السوق لأي زائر"
        checked={value === "public"}
        onChange={() => onChange("public")}
      />
      <RadioField
        id="visibility_private"
        name="visibility"
        label="خاص — لا يصل إليه إلا من تدعوه أو تسجّله"
        checked={value === "private"}
        onChange={() => onChange("private")}
      />
      {error !== undefined && error !== "" && (
        <p role="alert" className="text-xs text-danger-ink">
          {error}
        </p>
      )}
    </fieldset>
  );
}
