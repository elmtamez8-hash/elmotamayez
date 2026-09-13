import { beforeEach, expect, it, vi } from "vitest";

/*
| العطبُ الذي قتلَ كلَّ صفحةِ مقالٍ على الإنتاج (٢٠٢٦-٠٩-١٣).
|
| Next يُسلِّمُ جزءَ المسارِ مُرمَّزاً، و`publicApi.article()` يُرمِّزُ ما يصلُه —
| فالسلَغُ العربيُّ كان يُرمَّزُ مرّتَين، ويصلُ الخادمَ `%25D8%A3…` بدلَ
| `%D8%A3…`. قِيسَ على الإنتاج: واجهةُ البرمجةِ تُجيبُ ‏٢٠٠ بترميزٍ واحدٍ و‏٤٠٤
| بترميزَين، والصفحاتُ الستُّ كلُّها كانت تعرضُ «غير متاح».
|
| ⚠️ **والسلَغُ اللاتينيُّ لا يرى هذا أبداً**: `encodeURIComponent('a-b')` هو
| `'a-b'`، فالترميزُ المزدوجُ بلا أثرٍ على `/courses` و`/teachers` — ولهذا يجبُ
| أن يكونَ السلَغُ في هذا الملفِّ عربيّاً. اختبارٌ بسلَغٍ لاتينيٍّ يمرُّ فوقَ
| البناءِ المكسورِ تماماً.
|
| ⚠️ ويُقاسُ على `generateMetadata` لا على دالّةٍ خاصّة: `loadArticle` غيرُ
| مُصدَّرة، والبابانِ (الوسومُ والصفحة) يمرّانِ بها معاً.
*/

const article = vi.fn();

vi.mock("@/lib/platform", () => ({ platformName: async () => "المتميّز" }));

vi.mock("@/lib/public-api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/public-api")>();

  return {
    ...actual,
    publicApi: { article: (slug: string) => article(slug) },
  };
});

const SLUG = "أغلق-الكتاب-المذاكرة-الحقيقية";

beforeEach(() => {
  article.mockReset();
  article.mockResolvedValue({
    data: {
      uuid: "3f0f2f3e-0f6f-4c1e-9a1e-2f1a0b7c5d33",
      slug: SLUG,
      title: "أغلقِ الكتاب",
      excerpt: null,
      cover_url: null,
      published_at: "2026-09-10T08:00:00Z",
      updated_at: "2026-09-10T08:00:00Z",
      body_html: "<p>نصّ</p>",
      seo_title: null,
      seo_description: null,
      canonical_url: null,
      summary: null,
      faq: [],
      is_indexable: true,
      related_teachers: [],
      related_courses: [],
    },
  });
});

it("asks the API for the decoded slug, never the encoded route segment", async () => {
  const { generateMetadata } = await import("./page");

  await generateMetadata({
    // ما يُسلِّمُه Next فعلاً: الجزءُ كما هو في العنوان، مُرمَّزاً.
    params: Promise.resolve({ slug: encodeURIComponent(SLUG) }),
  });

  expect(article).toHaveBeenCalledWith(SLUG);
});
