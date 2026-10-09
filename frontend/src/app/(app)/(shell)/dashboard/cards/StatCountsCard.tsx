"use client";

import { useCallback, useEffect, useState, type ComponentType } from "react";

import { api } from "@/lib/api";
import { arabicNumber } from "@/lib/numerals";
import { CertificateIcon, LearningIcon, VerifiedIcon, type IconProps } from "@/components/icons";
import { DashboardCard } from "./DashboardCard";
import { readActiveEnrolments } from "./ProgressChartCard";

/**
 * ثلاثةُ أعدادٍ عن الطالب: كورساتٌ جارية · مكتملة · شهادات.
 *
 * ⚠️ **كلُّ رقمٍ من `meta.total`، ولا واحدَ منها من طولِ مصفوفة** (`FR-012`).
 * القراءتانِ تُقسِّمانِ بخمسةَ عشرَ صفّاً، فعدُّ الصفوفِ يتوقّفُ عندَ ١٥ مهما بلغَ
 * عددُ كورساتِ الطالب — رقمٌ خاطئٌ يبدو صحيحاً، وهو ما كانت تعرضُه هذه الشاشةُ
 * قبلَ اليوم.
 *
 * ⚠️ **وثلاثةُ طلباتٍ لا اثنان**، وهذا تصحيحٌ لحسابِ المهمّة: «جارية» و«مكتملة»
 * مجموعانِ مختلفانِ لا يُشتقُّ أحدُهما من الآخرِ ولا من مجموعٍ واحد، ولذلك كسبَ
 * فهرسُ التسجيلاتِ مُرشِّحَ `status` في هذه المرحلةِ نفسِها. ولا `per_page=1`:
 * `paginate(15)` لا تقرأُ ذلك المعامِلَ أصلاً، ومعامِلٌ في العنوانِ لا يفعلُ شيئاً
 * ادّعاءٌ يقرؤُه القارئُ التالي على أنّه عقد.
 *
 * ⚠️ و`allSettled` لا `all`: رفضُ أحدِ الثلاثةِ يترُكُ الاثنَينِ الآخرَينِ
 * معروضَين — العطلُ المسجَّلُ في صدرِ هذه الشاشةِ هو بالضبطِ رفضٌ واحدٌ أسقطَ ما
 * لا علاقةَ له به.
 */
type Counts = { active: number | null; completed: number | null; certificates: number | null };

async function total(path: string): Promise<number | null> {
  try {
    const result = await api.get<{ meta?: { total?: number } }>(path);

    return result.meta?.total ?? null;
  } catch {
    return null;
  }
}

/**
 * ⚠️ الكورساتُ الجاريةُ وحدَها تمرُّ بالقراءةِ المشتركة، لأنّها **الوحيدةُ التي
 * ترسمُها بطاقةٌ ثانية**: `ProgressChartCard` يرسمُ شريطاً لكلِّ صفٍّ منها، ورقمٌ
 * هنا وأشرطةٌ هناك من ردَّينِ مختلفَينِ يتناقضانِ على شاشةٍ واحدة (`FR-018`).
 * والمكتملةُ والشهاداتُ لا قارئَ ثانيَ لهما، فتبقيانِ كما هما.
 */
async function activeTotal(): Promise<number | null> {
  try {
    return (await readActiveEnrolments()).meta?.total ?? null;
  } catch {
    return null;
  }
}

export function StatCountsCard() {
  const [counts, setCounts] = useState<Counts>({
    active: null,
    completed: null,
    certificates: null,
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    void Promise.all([
      activeTotal(),
      total("/enrollments?status=completed"),
      total("/certificates"),
    ]).then(([active, completed, certificates]) => {
      setCounts({ active, completed, certificates });
      // فشلُ الثلاثةِ معاً ليس «صفراً» بل «تعذّرَ التحميل» (`FR-014`).
      setError(
        active === null && completed === null && certificates === null
          ? "تعذّر تحميل الأعداد"
          : null,
      );
      setLoading(false);
    });
  }, []);

  useEffect(load, [load]);

  return (
    <DashboardCard
      title="دراستك في أرقام"
      Icon={LearningIcon}
      href="/enrollments"
      loading={loading}
      error={error}
      onRetry={load}
    >
      <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Stat label="كورسات جارية" value={counts.active} Icon={LearningIcon} />
        <Stat label="كورسات مكتملة" value={counts.completed} Icon={VerifiedIcon} />
        <Stat label="الشهادات" value={counts.certificates} Icon={CertificateIcon} />
      </dl>
    </DashboardCard>
  );
}

function Stat({ label, value, Icon }: { label: string; value: number | null; Icon: ComponentType<IconProps> }) {
  return (
    <div className="group/stat flex items-center gap-4 rounded-2xl border border-line bg-surface p-4 transition duration-200 hover:border-primary/40">
      <span
        aria-hidden="true"
        className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary-soft text-primary-ink transition duration-200 group-hover/stat:bg-primary group-hover/stat:text-white"
      >
        <Icon className="h-6 w-6" />
      </span>
      <div className="min-w-0">
      <dt className="text-sm font-bold text-ink-muted">{label}</dt>
      {/* «—» لا «٠»: الصفرُ جملةٌ عن طالبٍ لا كورسَ له، وهذه حالةُ رقمٍ لم يصل. */}
      <dd className="mt-1 text-3xl font-extrabold leading-none text-primary-ink">
        <bdi>{value === null ? "—" : arabicNumber(value)}</bdi>
      </dd>
      </div>
    </div>
  );
}
