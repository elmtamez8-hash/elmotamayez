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
const teachers = vi.fn();
const courses = vi.fn();

vi.mock("@/lib/public-api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/public-api")>();

  return {
    ...actual,
    publicApi: {
      articles: () => articles(),
      teachers: (params: Record<string, string>) => teachers(params),
      courses: (params: Record<string, string>) => courses(params),
    },
  };
});

const EMPTY_PAGE = {
  data: [],
  meta: { total: 0, last_page: 1, current_page: 1, per_page: 48 },
};

beforeEach(() => {
  articles.mockReset();
  teachers.mockReset().mockResolvedValue(EMPTY_PAGE);
  courses.mockReset().mockResolvedValue(EMPTY_PAGE);
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

it("lists every public teacher and course in chunk 0, walking the pages at the API's cap", async () => {
  articles.mockRejectedValue(new Error("API unreachable"));
  teachers.mockImplementation(async (params: Record<string, string>) => ({
    data:
      params.page === "1"
        ? [{ uuid: "t-1", slug: "أحمد-علي" }]
        : [{ uuid: "t-2", slug: null }],
    meta: { total: 2, last_page: 2, current_page: Number(params.page), per_page: 48 },
  }));
  courses.mockResolvedValue({
    data: [{ uuid: "c-1", slug: "رياضيات-٣" }],
    meta: { total: 1, last_page: 1, current_page: 1, per_page: 48 },
  });

  const sitemap = (await import("./sitemap")).default;
  const entries = await sitemap({ id: Promise.resolve("0") });
  const origin = new URL(entries[0].url).origin;
  const urls = entries.map((entry) => entry.url);

  expect(urls).toContain(`${origin}/teachers/${encodeURIComponent("أحمد-علي")}`);
  // No slug yet: the uuid, which is what the card links to.
  expect(urls).toContain(`${origin}/teachers/t-2`);
  expect(urls).toContain(`${origin}/courses/${encodeURIComponent("رياضيات-٣")}`);

  // ⚠️ 48, never the articles' 200 — the marketplace validates `per_page` and
  // answers 422 above its cap, which would empty this half of the map silently.
  expect(teachers).toHaveBeenCalledTimes(2);
  expect(teachers.mock.calls.map(([params]) => params)).toEqual([
    { page: "1", per_page: "48" },
    { page: "2", per_page: "48" },
  ]);
  expect(courses).toHaveBeenCalledWith({ page: "1", per_page: "48" });
});

it("keeps the courses and the static pages when the teachers feed fails", async () => {
  articles.mockRejectedValue(new Error("API unreachable"));
  teachers.mockRejectedValue(new Error("422"));
  courses.mockResolvedValue({
    data: [{ uuid: "c-1", slug: "كورس" }],
    meta: { total: 1, last_page: 1, current_page: 1, per_page: 48 },
  });

  const sitemap = (await import("./sitemap")).default;
  const paths = (await sitemap({ id: Promise.resolve("0") })).map(
    (entry) => new URL(entry.url).pathname,
  );

  expect(paths).toContain("/");
  expect(paths).toContain(`/courses/${encodeURIComponent("كورس")}`);
  expect(paths.some((path) => path.startsWith("/teachers/"))).toBe(false);
});

it("does not repeat the teachers and courses in a later chunk", async () => {
  articles.mockResolvedValue({
    data: [],
    meta: { total: 300, last_page: 2, current_page: 2, per_page: 200 },
  });

  const sitemap = (await import("./sitemap")).default;
  await sitemap({ id: Promise.resolve("1") });

  expect(teachers).not.toHaveBeenCalled();
  expect(courses).not.toHaveBeenCalled();
});

it("is rendered per request, never frozen at build time", async () => {
  // The build has no API and no `SITE_URL`, and a build-time map was served
  // with `localhost` addresses after a deploy.
  expect((await import("./sitemap")).dynamic).toBe("force-dynamic");
});
