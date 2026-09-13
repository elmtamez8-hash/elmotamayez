import { beforeEach, expect, it, vi } from "vitest";

/*
| ⚠️ الخريطةُ كانت **فارغةً تماماً** على الإنتاج وعلى المُطوِّرِ معاً
| (٢٠٢٦-٠٩-١٣): `<urlset>` بلا سطرٍ واحد. السببُ توقيعٌ خطأ — `id` مُصرَّحٌ
| `number` بينما Next يُمرِّرُ `Promise<string>` — فـ`id === 0` كاذبةٌ دائماً
| ويسقطُ النصفُ الثابتُ الذي لا يعتمدُ على شبكةٍ أصلاً.
|
| ⚠️ **والقياسُ على النصفِ الثابتِ عمداً.** اختبارٌ يتحقّقُ من مقالاتٍ وحدَها
| يمرُّ فوقَ هذا العطبِ إن كانَ الـAPI مُحاكىً بنجاح: النصفُ الثابتُ هو الوحيدُ
| الذي **يجبُ** أن يظهرَ مهما حدثَ للشبكة، وغيابُه هو ما يقولُ لمحرّكِ البحثِ إنّ
| الموقعَ بلا صفحات.
*/

const articles = vi.fn();

vi.mock("@/lib/public-api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/public-api")>();

  return { ...actual, publicApi: { articles: () => articles() } };
});

beforeEach(() => {
  articles.mockReset();
});

it("carries the static pages in chunk 0, even with the API down", async () => {
  articles.mockRejectedValue(new Error("API unreachable"));

  const sitemap = (await import("./sitemap")).default;
  // ما يُمرِّرُه Next فعلاً: وعدٌ بنصّ، لا رقم.
  const entries = await sitemap({ id: Promise.resolve("0") });

  const paths = entries.map((entry) => new URL(entry.url).pathname);

  expect(paths).toContain("/");
  expect(paths).toContain("/blog");
  expect(paths).toContain("/teachers");
  expect(paths).toContain("/courses");
});

it("adds the articles to chunk 0 beside the static pages", async () => {
  articles.mockResolvedValue({
    data: [
      {
        slug: "أغلق-الكتاب",
        updated_at: "2026-09-10T08:00:00Z",
      },
    ],
    meta: { total: 1, last_page: 1, current_page: 1, per_page: 200 },
  });

  const sitemap = (await import("./sitemap")).default;
  const entries = await sitemap({ id: Promise.resolve("0") });

  // مُرمَّزٌ مرّةً واحدة: سلَغٌ عربيٌّ خامٌّ ليسَ `<loc>` صالحاً، وخريطةٌ غيرُ
  // صالحةٍ تُرفَضُ كاملةً لا جزئيّاً.
  expect(entries.map((entry) => entry.url)).toContain(
    `${new URL(entries[0].url).origin}/blog/${encodeURIComponent("أغلق-الكتاب")}`,
  );
});

it("gives a later chunk its articles and none of the static pages", async () => {
  // وإلّا تكرّرَ `/` و`/blog` في كلِّ شريحةٍ من الخريطة.
  articles.mockResolvedValue({
    data: [],
    meta: { total: 300, last_page: 2, current_page: 2, per_page: 200 },
  });

  const sitemap = (await import("./sitemap")).default;

  expect(await sitemap({ id: Promise.resolve("1") })).toEqual([]);
});
