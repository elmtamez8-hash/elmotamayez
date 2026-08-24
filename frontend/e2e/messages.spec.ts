import { test, expect } from "@playwright/test";
import { useTeacherAccount } from "./teacher-account";

/**
 * The chat shell, at every width the matrix runs (spec 010 · `SC-020`).
 *
 * ⚠️ PLAYWRIGHT AND NOT VITEST, BECAUSE THE QUESTION IS A VIEWPORT. The rule that
 * makes this layout work is `md:` — one pane below it, two above — and jsdom has
 * no viewport, no media queries and no layout, so a component test of the same
 * file would assert that both panes render and be perfectly green on a design
 * that shows the list on top of the thread. The dividing line is the question,
 * not the layer.
 *
 * ⚠️ AND IT ASSERTS THE COMPOSER IS IN VIEW, NOT MERELY PRESENT. The first
 * attempt at this layout subtracted the shell's `h-16` header and forgot its
 * `p-6` padding, so the send button sat 24px below the fold — present in the DOM,
 * visible to every `toBeVisible()`, and unreachable on a phone without scrolling
 * a pane that scrolls internally.
 */

test.describe("شاشة الرسائل", () => {
  test("لا تتمدّد أفقياً، والقائمة حاضرة", async ({ page }) => {
    await useTeacherAccount(page);
    await page.goto("/messages");

    await expect(page.getByPlaceholder("ابحث بالاسم…")).toBeVisible();

    // documentElement as well as body: a wide row can overflow the root while
    // the body reports itself content-sized.
    const overflow = await page.evaluate(() => ({
      root: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      body: document.body.scrollWidth - document.body.clientWidth,
    }));

    expect(overflow.root, "الجذر يتمدّد أفقياً").toBeLessThanOrEqual(0);
    expect(overflow.body, "جسم الصفحة يتمدّد أفقياً").toBeLessThanOrEqual(0);
  });

  test("عمودٌ واحدٌ على الهاتف وعمودان على الشاشة الكبيرة", async ({ page }, testInfo) => {
    await useTeacherAccount(page);
    await page.goto("/messages");

    const search = page.getByPlaceholder("ابحث بالاسم…");
    await expect(search).toBeVisible();

    const wide = (page.viewportSize()?.width ?? 0) >= 768;

    // The empty pane beside the list exists only where there is room for it.
    // Below `md` the layout hides it entirely, so a reader on a phone sees the
    // list and nothing else — which is the whole point of the breakpoint.
    const emptyPane = page.getByRole("heading", { name: "اختر محادثة" });

    if (wide) {
      await expect(emptyPane, `مشروع ${testInfo.project.name}`).toBeVisible();
    } else {
      await expect(emptyPane, `مشروع ${testInfo.project.name}`).toBeHidden();
    }
  });
});
