import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { ManagedArticle } from "@/lib/blog";

import ManageBlogPage from "./page";

/**
 * ⛔ المدوّنةُ كانت قدرةً بلا باب.
 *
 * `cms.create` و`cms.update` و`cms.delete` و`cms.publish` في دورَي المدرّسِ
 * والمساعدِ منذُ ٠١١، وخلفَها موديلٌ وسياسةٌ وتوليدُ رابطٍ عربيٍّ وإعلانُ IndexNow
 * وخريطةُ موقعٍ ومدوّنةٌ عامّة — ولا شاشةَ في المنتَجِ تكتبُ مقالاً، ولا مسارٌ
 * تستدعيه الواجهة. `/admin` — قارئتُها الوحيدةُ — لا تقبلُ دوراً في مساحةِ عمل.
 *
 * ⚠️ **ولا يُمَوَّهُ `@/lib/api` هنا عمداً.** `errors.ts` يستوردُ منه `ApiError`
 * ويبدأُ `userMessage()` بـ`err instanceof ApiError`، فتمويهُ الوحدةِ يجعلُ
 * الصنفَ المرجعيَّ غيرَ الصنفِ المرميّ، ويسقطُ مسارُ الخطأِ داخلَ المعالِجِ بدلَ
 * أن يُقاسَ توكيدُه. الدالّتانِ نقيّتانِ فتعملانِ على `ApiError` حقيقيّ.
 */
const blog = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  remove: vi.fn(),
}));
const auth = vi.hoisted(() => ({
  user: { permissions: ["cms.update", "cms.create", "cms.delete", "cms.publish"] },
}));

vi.mock("@/lib/blog", () => ({ blog }));
vi.mock("@/lib/auth-context", () => ({ useAuth: () => auth }));

function article(overrides: Partial<ManagedArticle> = {}): ManagedArticle {
  return {
    uuid: "a-1",
    title: "خطة المراجعة",
    slug: "خطة-المراجعة",
    excerpt: null,
    body: "النصّ",
    status: "published",
    published_at: "2026-09-01T08:00:00.000000Z",
    seo_title: null,
    seo_description: null,
    canonical_url: null,
    created_at: "2026-09-01T08:00:00.000000Z",
    ...overrides,
  };
}

describe("ManageBlogPage", () => {
  beforeEach(() => {
    blog.list.mockReset();
    blog.create.mockReset();
    blog.update.mockReset();
    blog.remove.mockReset();
    auth.user = {
      permissions: ["cms.update", "cms.create", "cms.delete", "cms.publish"],
    };
    blog.list.mockResolvedValue({ data: [article()] });
  });

  it("lists the teacher's own articles", async () => {
    render(<ManageBlogPage />);

    expect(await screen.findByText("خطة المراجعة")).toBeTruthy();
    expect(screen.getByRole("button", { name: "مقال جديد" })).toBeTruthy();
  });

  it("leaves the publishing fields open for a teacher who holds cms.publish", async () => {
    render(<ManageBlogPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عدّل" }));

    // The positive control. A field disabled for everybody is a teacher who
    // cannot publish their own blog, and it fails just as silently.
    expect((screen.getByLabelText(/الحالة/) as HTMLSelectElement).disabled).toBe(false);
    expect((screen.getByLabelText(/تاريخ النشر/) as HTMLInputElement).disabled).toBe(false);
  });

  it("shows an assistant the publishing fields and lets them change neither", async () => {
    /*
     * ⚠️ معطَّلانِ لا مخفيّان. المساعدُ الذي يكتبُ ويحرّرُ يحتاجُ أن يرى أمنشورٌ
     * ما كتبَه أم لا — وإخفاءُ الحقلِ يجعلُ «لماذا لا تظهرُ مسوّدتي؟» سؤالاً لا
     * شيءَ على الشاشةِ يجيبُه. عكسُ زرِّ إلغاءِ الاشتراك، حيث لا طريقَ للقدرةِ
     * أصلاً فيكونُ الزرُّ الميّتُ دعوةً للسؤال.
     */
    auth.user = { permissions: ["cms.update", "cms.create"] };

    render(<ManageBlogPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عدّل" }));

    expect((screen.getByLabelText(/الحالة/) as HTMLSelectElement).disabled).toBe(true);
    expect((screen.getByLabelText(/تاريخ النشر/) as HTMLInputElement).disabled).toBe(true);
    // Still on screen, and still readable.
    expect((screen.getByLabelText(/الحالة/) as HTMLSelectElement).value).toBe("published");
    // And no delete: `cms.delete` is the teacher's alone.
    expect(screen.queryByRole("button", { name: "احذف" })).toBeNull();
  });

  it("sends null for an untouched optional field, never an empty string", async () => {
    /*
     * ⚠️ `nullable|url` و`nullable|date` لا تقبلانِ `""`. إرسالُ السلسلةِ الفارغةِ
     * يردُّ ٤٢٢ عن حقلٍ لم يفتحْه المدرّسُ أصلاً — خطأٌ عن شيءٍ لم يفعلْه.
     */
    blog.create.mockResolvedValue({ data: article({ uuid: "a-2" }) });

    render(<ManageBlogPage />);

    await userEvent.click(await screen.findByRole("button", { name: "مقال جديد" }));
    await userEvent.type(screen.getByLabelText(/العنوان/), "مقال");
    await userEvent.click(screen.getByRole("button", { name: "احفظ" }));

    await waitFor(() =>
      expect(blog.create).toHaveBeenCalledWith(
        expect.objectContaining({
          title: "مقال",
          canonical_url: null,
          published_at: null,
          slug: null,
        }),
      ),
    );
  });

  it("reloads from the server rather than trusting what was typed", async () => {
    // The slug is generated on the server from the Arabic title and a collision
    // resolves to `-2`; `published_at` may be stamped there too. What was typed
    // is not what was stored.
    blog.update.mockResolvedValue({ data: article() });

    render(<ManageBlogPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عدّل" }));
    await userEvent.click(screen.getByRole("button", { name: "احفظ" }));

    await waitFor(() => expect(blog.list).toHaveBeenCalledTimes(2));
  });

  it("never shows a raw error when a save is refused", async () => {
    blog.update.mockRejectedValue(new Error("Request failed with status 403"));

    render(<ManageBlogPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عدّل" }));
    await userEvent.click(screen.getByRole("button", { name: "احفظ" }));

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(screen.queryByText(/status 403/)).toBeNull();
  });
});
