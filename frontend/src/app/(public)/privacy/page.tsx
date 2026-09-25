import type { Metadata } from "next";

import { PageBanner } from "@/components/ui/PageBanner";
import { ShieldIcon } from "@/components/icons";

export const metadata: Metadata = {
  title: "سياسة الخصوصية",
};

/**
 * The policy itself, publicly readable (spec 013 · FR-004).
 *
 * ⚠️ IT STAYS PUBLIC AND IT STAYS AT THIS PATH. The page already existed, showing
 * `PolicyPlaceholder` — "the text has not been written yet" — and it is linked
 * from every footer. Putting the real policy behind authentication would leave a
 * visitor reading that placeholder for ever, and would put TWO routes in the
 * product called `privacy`: this one and the signed-in `/privacy` where a person
 * manages their own requests. Those are different pages for different questions.
 *
 * ⚠️ AND `robots: { index: false }` IS GONE, deliberately. A privacy policy that
 * asks search engines not to list it is not published in any meaningful sense —
 * the placeholder had that flag because there was nothing worth finding.
 *
 * The text is rendered SERVER-SIDE from the API, which renders Markdown per
 * response and strips raw HTML rather than escaping it (016's rule): the allowlist
 * is the Markdown feature set, so there is no sanitiser configuration to get wrong
 * and no stored HTML column to drift from its source.
 */
export const revalidate = 300;

type Category = {
  key: string;
  label: string;
  purpose: string;
  audience: string;
  is_required: boolean;
  retention_label_ar: string;
  subject_roles: ("student" | "teacher" | "parent")[];
};

/*
| ⚠️ **الكتالوجُ مقسَّمٌ بالدَّورِ لا مسروداً عائماً.** ثلاثةٌ وثلاثونَ فئةً في
| قائمةٍ واحدةٍ تجعلُ الزائرَ يقرأُ عن أرباحِ المدرّسِ وهو يُوازِنُ تسجيلَ ابنِه،
| وعن محاولاتِ الطالبِ في الاختباراتِ وهو مدرّسٌ يفكّرُ في الانضمام. والأقسامُ
| هنا تعرضُ الكلَّ — هذه صفحةُ السياسةِ وليست «بياناتي» — لكنّها تقولُ لكلِّ
| قارئٍ أينَ يقرأُ عن نفسِه.
|
| وفئةٌ تخصُّ أكثرَ من دَورٍ تظهرُ في كلِّ قسمٍ يخصُّه: «الاسم» و«رقم الهاتف»
| تُجمَعانِ من الجميع، وتكرارُهما أصدقُ من قسمٍ رابعٍ اسمُه «مشترك» يذهبُ إليه
| القارئُ ليكتشفَ أنّ نصفَ ما يخصُّه هناك.
*/
const SECTIONS: { role: Category["subject_roles"][number]; title: string; lead: string }[] = [
  {
    role: "student",
    title: "إن كنت طالباً",
    lead: "ما نجمعه عنك بصفتك دارساً على المنصّة.",
  },
  {
    role: "parent",
    title: "إن كنت وليّ أمر",
    lead: "ما نجمعه عنك بصفتك مسؤولاً عن حساب طالب.",
  },
  {
    role: "teacher",
    title: "إن كنت مدرّساً",
    lead: "ما نجمعه عنك بصفتك مدرّساً يعرض دروسه هنا.",
  },
];

/*
 * `MARKETPLACE_API_URL`, the one server-side base production sets (docker-compose.prod.yml).
 * This page read `API_URL`, which nothing sets, so on production it fetched
 * localhost inside the frontend container and showed «تعذّر تحميل نصّ السياسة»
 * on the page every footer links to (measured 2026-09-25).
 */
const PUBLIC_API = process.env.MARKETPLACE_API_URL ?? "http://localhost:8000/api/v1";

async function categories(): Promise<Category[]> {
  try {
    const response = await fetch(
      `${PUBLIC_API}/privacy/categories`,
      { cache: "no-store" },
    );

    if (!response.ok) return [];

    return ((await response.json()) as { data: Category[] }).data;
  } catch {
    /*
      ⚠️ قائمةٌ فارغةٌ لا صفحةُ خطأ. نصُّ السياسةِ فوقَها هو الوثيقةُ، والأقسامُ
      تفصيلٌ يشرحُه — فانقطاعٌ في القراءةِ لا يجوزُ أن يمنعَ النصَّ المنشورَ
      الذي يُحيلُ إليه كلُّ فوتر.
    */
    return [];
  }
}

async function policy(): Promise<{ version: string; body_html: string } | null> {
  try {
    const response = await fetch(`${PUBLIC_API}/privacy/policy`, {
      headers: { Accept: "application/json" },
      next: { revalidate: 300 },
    });

    if (!response.ok) {
      return null;
    }

    return (await response.json()) as { version: string; body_html: string };
  } catch {
    /*
     * ⚠️ NULL, NOT A THROW. This page is linked from every footer on the site; an
     * API hiccup must not take the whole public shell down with it. The fallback
     * below says plainly that the text could not be loaded rather than rendering
     * an empty policy, which would be worse than an error — it would read as a
     * platform that collects nothing.
     */
    return null;
  }
}

export default async function PrivacyPage() {
  const [document, catalogue] = await Promise.all([policy(), categories()]);

  return (
    <div className="space-y-6">
      <PageBanner
        icon={ShieldIcon}
        image="/marketplace/banner-privacy.webp"
        title="سياسة الخصوصية"
        description="ما البيانات التي نجمعها، ولماذا، ومن يطّلع عليها، وكيف تطلب حذفها."
      />

      {document === null ? (
        <p className="text-sm text-ink-muted">
          تعذّر تحميل نصّ السياسة الآن. أعد المحاولة بعد قليل.
        </p>
      ) : (
        <>
          {/*
            The rendered Markdown. `dangerouslySetInnerHTML` is safe here for one
            specific reason and not in general: the HTML comes from our own
            `MarkdownRenderer`, which STRIPS raw HTML from the source instead of
            escaping it — so nothing an author writes can produce a tag that was
            not generated by the Markdown grammar itself.
          */}
          <article
            className="prose-policy text-ink"
            dangerouslySetInnerHTML={{ __html: document.body_html }}
          />

          {/*
            ⚠️ THE VERSION IS SHOWN, and that is not decoration. A consent names
            the version it was given for, and publishing a new one invalidates
            every earlier acceptance on the next request — so a reader has to be
            able to tell which text they are looking at.
          */}
          <p className="text-xs text-ink-muted">نسخة السياسة: {document.version}</p>
        </>
      )}

      {catalogue.length > 0 && (
        <section className="space-y-6">
          <h2 className="text-lg font-semibold text-ink">تفصيل ما نجمعه، حسب نوع الحساب</h2>

          {SECTIONS.map((section) => {
            const rows = catalogue.filter((category) =>
              category.subject_roles.includes(section.role),
            );

            if (rows.length === 0) return null;

            return (
              <div key={section.role} className="rounded-2xl border border-line p-5">
                <h3 className="font-semibold text-ink">{section.title}</h3>
                <p className="mb-4 text-sm text-ink-muted">{section.lead}</p>

                <ul className="divide-y divide-line">
                  {rows.map((category) => (
                    <li key={category.key} className="py-3">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium text-ink">{category.label}</span>
                        {/* لازم/اختياري تفرقةٌ لا تُخفى — هي نفسُها تفرقةُ شاشةِ الموافقة. */}
                        <span className="text-xs text-ink-muted">
                          {category.is_required ? "لازم" : "اختياري"}
                        </span>
                      </div>
                      <p className="mt-1 text-sm text-ink-muted">{category.purpose}</p>
                      <p className="mt-1 text-xs text-ink-muted">
                        يطّلع عليه: {category.audience} · مدة الحفظ: {category.retention_label_ar}
                      </p>
                    </li>
                  ))}
                </ul>
              </div>
            );
          })}
        </section>
      )}
    </div>
  );
}
