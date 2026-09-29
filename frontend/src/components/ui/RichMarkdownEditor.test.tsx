import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, describe, expect, it, vi } from "vitest";

import { RichMarkdownEditor } from "./RichMarkdownEditor";

/**
 * The field as a page mounts it — through `next/dynamic`, so these also prove
 * the lazy chunk resolves and lands inside the label/hint/error frame.
 *
 * The Markdown round trip itself is measured in `rich-markdown/extensions.test.ts`
 * against the same schema; jsdom cannot type into a contenteditable faithfully,
 * so what is asserted here is the wiring a keyboard or screen reader meets.
 */

/*
 * jsdom has no layout, so it has no `getClientRects` on a Range or a text node's
 * element — and ProseMirror asks for them to scroll the caret into view after a
 * command. Empty rects are the honest answer for a document with no layout.
 */
beforeAll(() => {
  const noRects = () => ({ length: 0, item: () => null, [Symbol.iterator]: [][Symbol.iterator] });
  const zeroRect = () => new DOMRect(0, 0, 0, 0);
  for (const proto of [Range.prototype, Element.prototype] as unknown as Record<string, unknown>[]) {
    proto.getClientRects ??= noRects;
    proto.getBoundingClientRect ??= zeroRect;
  }
});

/** The editor chunk is a dynamic import; the first one in a run is slow. */
const LOADED = { timeout: 10_000 };

function mount(props: Partial<Parameters<typeof RichMarkdownEditor>[0]> = {}) {
  const onChange = vi.fn();
  render(
    <RichMarkdownEditor
      id="body"
      label="النصّ"
      hint="تلميح"
      value={"## عنوان\n\nنصّ **غامق**"}
      onChange={onChange}
      {...props}
    />,
  );
  return { onChange };
}

describe("RichMarkdownEditor", () => {
  it("loads the Markdown as formatted content in a labelled, described textbox", async () => {
    mount();

    const box = await screen.findByRole("textbox", { name: "النصّ" }, LOADED);

    expect(box.id).toBe("body");
    expect(box.getAttribute("contenteditable")).toBe("true");
    expect(box.getAttribute("aria-describedby")).toBe("body-hint");
    expect(box.getAttribute("dir")).toBe("rtl");
    // Rendered, not shown as source.
    expect(box.querySelector("h2")?.textContent).toBe("عنوان");
    expect(box.querySelector("strong")?.textContent).toBe("غامق");
    expect(box.textContent).not.toContain("**");
  });

  it("offers every tool as a named button in one toolbar, and no tool the server cannot render", async () => {
    mount();

    const toolbar = await screen.findByRole("toolbar", { name: "أدوات التنسيق" }, LOADED);
    const names = Array.from(toolbar.querySelectorAll("button")).map((b) =>
      b.getAttribute("aria-label"),
    );

    expect(names).toEqual([
      "غامق",
      "مائل",
      "عنوان رئيسي",
      "عنوان فرعي",
      "قائمة نقطية",
      "قائمة مرقّمة",
      "اقتباس",
      "إضافة رابط",
      "كود داخل السطر",
      "كتلة كود",
      "خط فاصل",
      "تراجع",
      "إعادة",
    ]);
    // A toggle announces its state; the shortcut is in the tooltip.
    const bold = screen.getByRole("button", { name: "غامق" });
    expect(bold.getAttribute("aria-pressed")).toBe("false");
    expect(bold.getAttribute("title")).toBe("غامق (Ctrl+B)");
    // Nothing to undo on a freshly loaded article.
    expect((screen.getByRole("button", { name: "تراجع" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("marks a formatting button pressed when the caret sits in that format", async () => {
    mount({ value: "**غامق**" });

    const box = await screen.findByRole("textbox", { name: "النصّ" }, LOADED);
    box.focus();

    await waitFor(() =>
      expect(screen.getByRole("button", { name: "غامق" }).getAttribute("aria-pressed")).toBe("true"),
    );
  });

  it("does not rewrite a stored body that is not in canonical form when it opens", async () => {
    // `_x_`, `*` bullets and a setext heading re-serialise differently; opening
    // the article must not report a change nobody made.
    const { onChange } = mount({ value: "Title\n===\n\n_مائل_ و __غامق__\n\n* بند" });

    await screen.findByRole("button", { name: "غامق" }, LOADED);
    await new Promise((resolve) => setTimeout(resolve, 50));

    expect(onChange).not.toHaveBeenCalled();
  });

  it("asks for a link in its own box and refuses a javascript: address", async () => {
    const { onChange } = mount();

    await userEvent.click(await screen.findByRole("button", { name: "إضافة رابط" }, LOADED));

    const url = screen.getByLabelText("عنوان الرابط");
    await userEvent.type(url, "javascript:alert(1)");
    await userEvent.click(screen.getByRole("button", { name: "تطبيق" }));

    expect(screen.getByRole("alert").textContent).toContain("https://");
    expect(onChange).not.toHaveBeenCalled();

    await userEvent.click(screen.getByRole("button", { name: "إلغاء" }));
    expect(screen.queryByLabelText("عنوان الرابط")).toBeNull();
  });

  it("inserts a link as Markdown when nothing is selected", async () => {
    const { onChange } = mount({ value: "" });

    await userEvent.click(await screen.findByRole("button", { name: "إضافة رابط" }, LOADED));
    await userEvent.type(screen.getByLabelText("عنوان الرابط"), "example.com{Enter}");

    await waitFor(() =>
      expect(onChange).toHaveBeenLastCalledWith("[example.com](https://example.com)"),
    );
  });

  it("emits Markdown from a toolbar command", async () => {
    const { onChange } = mount({ value: "سطر" });

    await screen.findByRole("textbox", { name: "النصّ" }, LOADED);
    await userEvent.click(screen.getByRole("button", { name: "عنوان رئيسي" }));

    await waitFor(() => expect(onChange).toHaveBeenLastCalledWith("## سطر"));
  });

  it("locks the text and every tool when disabled", async () => {
    mount({ disabled: true });

    const box = await screen.findByRole("textbox", { name: "النصّ" }, LOADED);

    expect(box.getAttribute("contenteditable")).toBe("false");
    const toolbar = screen.getByRole("toolbar");
    for (const button of Array.from(toolbar.querySelectorAll("button"))) {
      expect(button.disabled).toBe(true);
    }
  });

  it("shows the error under the field and marks the textbox invalid", async () => {
    mount({ error: "النصّ طويل جدّاً" });

    const box = await screen.findByRole("textbox", { name: "النصّ" }, LOADED);

    expect(box.getAttribute("aria-invalid")).toBe("true");
    expect(box.getAttribute("aria-describedby")).toBe("body-hint body-error");
    expect(screen.getByRole("alert").textContent).toBe("النصّ طويل جدّاً");
  });
});
