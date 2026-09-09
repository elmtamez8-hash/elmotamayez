import {
  AcademicCapIcon,
  ArabicIcon,
  BiologyIcon,
  ChemistryIcon,
  ComputerIcon,
  GeographyIcon,
  HistoryIcon,
  LanguageIcon,
  MathIcon,
  MosqueIcon,
  PhysicsIcon,
  ScienceIcon,
  SocialStudiesIcon,
  VocabularyIcon,
  type IconProps,
} from "@/components/icons";
import type { Taxonomy } from "@/lib/public-api";

/**
 * رمزٌ لكلِّ مادّة — **مكتوبٌ مرّةً واحدةً في المنتَجِ كلِّه**.
 *
 * ⚠️ أُخرِجَ من `SubjectsGrid` يومَ احتاجَته شريحاتُ المواد في صفحةِ المدرّس
 * (طلبُ ٢٠٢٦-٠٩-٠٨). خريطةٌ ثانيةٌ منسوخةٌ هناك كانت ستفترقُ عن هذه عندَ أوّلِ
 * مادّةٍ تُضاف: الشبكةُ ترسمُ رمزَ الفيزياء والشريحةُ ترسمُ قبّعةَ تخرّج، بلا
 * خطأٍ في أيِّ مكان. عائلةُ `BookingEligibility` و`ListLeaderboardScopes` نفسُها.
 *
 * والمفتاحُ **السَّبيكة** لا الاسمُ العربيّ: `name_ar` يُحرَّرُ من `‎/admin`،
 * وإعادةُ تسميةٍ كانت ستُسقِطُ المادّةَ إلى الاحتياطيِّ بصمت.
 */
const BY_SLUG: Record<string, (props: IconProps) => React.ReactElement> = {
  math: MathIcon,
  science: ScienceIcon,
  physics: PhysicsIcon,
  chemistry: ChemistryIcon,
  biology: BiologyIcon,
  arabic: ArabicIcon,
  english: LanguageIcon,
  french: VocabularyIcon,
  "islamic-studies": MosqueIcon,
  "social-studies": SocialStudiesIcon,
  history: HistoryIcon,
  geography: GeographyIcon,
  "computer-science": ComputerIcon,
};

/**
 * ⚠️ `subjects.icon` هو رافعةُ المشغِّلِ الوحيدة، وكانت تصلُ غيرَ مقروءة. مادّةٌ
 * تُضافُ من `‎/admin` غداً سَبيكتُها لم يسمعْ بها هذا الملفُّ قطّ، فبلا هذه
 * الخريطةِ الثانيةِ تبقى على قبّعةِ التخرّجِ مهما كتبَ المشغّل. الأسماءُ هي التي
 * يكتبُها `TaxonomySeeder`.
 */
const BY_ICON_NAME: Record<string, (props: IconProps) => React.ReactElement> = {
  calculator: MathIcon,
  beaker: ChemistryIcon,
  "book-open": VocabularyIcon,
  language: LanguageIcon,
  "computer-desktop": ComputerIcon,
};

export function subjectIcon(subject: Taxonomy) {
  return (
    BY_SLUG[subject.slug] ??
    (subject.icon ? BY_ICON_NAME[subject.icon] : undefined) ??
    AcademicCapIcon
  );
}
