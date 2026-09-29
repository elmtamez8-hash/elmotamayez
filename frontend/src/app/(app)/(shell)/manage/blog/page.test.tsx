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

/*
 * The real editor is ProseMirror behind `next/dynamic`; its Markdown behaviour is
 * tested in `components/ui/rich-markdown/`. Here it is a textarea that honours the
 * same contract — Markdown in through `value`, Markdown out through `onChange`,
 * the field's id and label — so these tests measure the FORM's wiring: that the
 * article's stored body reaches the editor and the editor's output reaches the
 * save.
 */
vi.mock("@/components/ui/RichMarkdownEditor", () => ({
  RichMarkdownEditor: ({
    id,
    label,
    value,
    onChange,
  }: {
    id: string;
    label: string;
    value: string;
    onChange: (markdown: string) => void;
  }) => (
    <div>
      <label htmlFor={id}>{label}</label>
      <textarea
        id={id}
        data-testid="rich-markdown-editor"
        defaultValue={value}
        onChange={(event) => onChange(event.target.value)}
      />
    </div>
  ),
}));

const STORED_BODY = "## مقدّمة\n\nنصّ **غامق**.";

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

  /*
   * ⛔ THE LIST IS PAGED AT 15, SO THE CHIPS AND THE SEARCH ASK THE SERVER. They
   * used to narrow page one in the browser — the sixteenth article could not be
   * reached, and a chip said «no drafts» about a blog full of them.
   */
  function serveBlog() {
    const all = [
      article(),
      article({ uuid: "a-2", title: "مسوّدة الأسبوع", status: "draft", published_at: null }),
    ];

    blog.list.mockImplementation(async (params: { page?: number; q?: string; status?: string } = {}) => {
      const matching = all.filter(
        (row) => (!params.status || row.status === params.status) && (!params.q || row.title.includes(params.q)),
      );
      const page = params.page ?? 1;
      const perPage = !params.status && !params.q ? 1 : 15;

      return {
        data: matching.slice((page - 1) * perPage, page * perPage),
        meta: {
          total: matching.length,
          current_page: page,
          last_page: Math.max(1, Math.ceil(matching.length / perPage)),
          counts: { draft: 1, published: 1 },
        },
      };
    });
  }

  it("narrows the list by status on the server, with the server's counts on the chips", async () => {
    serveBlog();

    render(<ManageBlogPage />);

    await screen.findByText("خطة المراجعة");
    expect(screen.getByRole("button", { name: "الكل ٢" })).toBeTruthy();

    await userEvent.click(screen.getByRole("button", { name: "المسوّدات ١" }));

    expect(blog.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, status: "draft" }));
    expect(await screen.findByText("مسوّدة الأسبوع")).toBeTruthy();
    expect(screen.queryByText("خطة المراجعة")).toBeNull();
  });

  it("searches on the server once the typing stops", async () => {
    serveBlog();

    render(<ManageBlogPage />);

    await userEvent.type(await screen.findByLabelText("ابحث في مقالاتك"), "مسوّدة");

    await waitFor(() =>
      expect(blog.list).toHaveBeenLastCalledWith(expect.objectContaining({ q: "مسوّدة" })),
    );
    expect(await screen.findByText("مسوّدة الأسبوع")).toBeTruthy();
    // One request for the settled text, not one per keystroke.
    expect(blog.list.mock.calls.filter(([params]) => (params?.q ?? "") !== "")).toHaveLength(1);
  });

  it("reaches the rest of the blog with «عرض المزيد»", async () => {
    serveBlog();

    render(<ManageBlogPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عرض المزيد" }));

    expect(blog.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }));
    expect(await screen.findByText("مسوّدة الأسبوع")).toBeTruthy();
    expect(screen.getByText("خطة المراجعة")).toBeTruthy();
  });

  it("keeps the header and says so in place when the list cannot be read", async () => {
    blog.list.mockRejectedValue(new Error("Failed to fetch"));

    render(<ManageBlogPage />);

    expect(await screen.findByRole("button", { name: "مقال جديد" })).toBeTruthy();
    expect(screen.queryByText("Failed to fetch")).toBeNull();
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

  it("asks before deleting, and deletes nothing on the first press", async () => {
    // Deleting takes the article off the public blog with no restore on any
    // screen — one press used to do it.
    blog.remove.mockResolvedValue(undefined);

    render(<ManageBlogPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عدّل" }));
    await userEvent.click(screen.getByRole("button", { name: "احذف" }));

    expect(screen.getByText("سيختفي المقال من مدوّنتك العامّة ولا يمكن استرجاعه من هنا.")).toBeTruthy();
    expect(blog.remove).not.toHaveBeenCalled();

    await userEvent.click(screen.getByRole("button", { name: "احذف المقال" }));

    await waitFor(() => expect(blog.remove).toHaveBeenCalledTimes(1));
    expect(blog.remove).toHaveBeenCalledWith("a-1");
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

  it("loads the stored Markdown into the rich editor and saves what it hands back", async () => {
    blog.list.mockResolvedValue({ data: [article({ body: STORED_BODY })] });
    blog.update.mockResolvedValue({ data: article() });

    render(<ManageBlogPage />);

    await userEvent.click(await screen.findByRole("button", { name: "عدّل" }));

    const body = screen.getByTestId("rich-markdown-editor") as HTMLTextAreaElement;
    // The editor receives the SOURCE, not a render of it.
    expect(body.value).toBe(STORED_BODY);
    expect(body.id).toBe("article-body");

    await userEvent.clear(body);
    await userEvent.type(body, "- أوّلاً");
    await userEvent.click(screen.getByRole("button", { name: "احفظ" }));

    await waitFor(() =>
      expect(blog.update).toHaveBeenCalledWith("a-1", expect.objectContaining({ body: "- أوّلاً" })),
    );
  });

  it("gives a new article an empty editor, and sends null for an empty body", async () => {
    blog.create.mockResolvedValue({ data: article({ uuid: "a-2" }) });

    render(<ManageBlogPage />);

    await userEvent.click(await screen.findByRole("button", { name: "مقال جديد" }));
    expect((screen.getByTestId("rich-markdown-editor") as HTMLTextAreaElement).value).toBe("");

    await userEvent.type(screen.getByLabelText(/العنوان/), "مقال");
    await userEvent.click(screen.getByRole("button", { name: "احفظ" }));

    await waitFor(() =>
      expect(blog.create).toHaveBeenCalledWith(expect.objectContaining({ body: null })),
    );
  });
});
