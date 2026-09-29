import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { BookIcon, UsersIcon } from "@/components/icons";
import { TONE_CLASSES } from "@/lib/labels";

import { Badge } from "./Badge";
import { RecordList, RecordRow } from "./RecordList";

/*
| What a page agent copies from this kit is the SHAPE, so the shape is what is
| pinned: one `<li>` per record carrying the shared stagger index, a real heading
| at the level asked for, the meta as a definition list whose labels a screen
| reader hears even when the eye does not, and the actions rendered ONCE.
|
| ⚠️ jsdom has no layout. Nothing here can see where the grid puts the actions
| at 375px, or how far the stretched link's overlay reaches — that is checked by
| opening a screen, and `docs/design/manage-pages.md` says so.
*/

describe("RecordList", () => {
  it("wraps every record in its own list item with the shared stagger index", () => {
    render(
      <RecordList label="الإعلانات">
        <RecordRow title="الأوّل" />
        <RecordRow title="الثاني" />
        <RecordRow title="الثالث" />
      </RecordList>,
    );

    const list = screen.getByRole("list", { name: "الإعلانات" });
    const items = within(list).getAllByRole("listitem");

    expect(items).toHaveLength(3);
    items.forEach((item, index) => {
      expect(item.className).toContain("stagger-item");
      expect(item.style.getPropertyValue("--stagger-i")).toBe(String(index));
    });
  });

  it("is named by the heading it points at", () => {
    render(
      <>
        <h3 id="saved">المحفوظة</h3>
        <RecordList labelledBy="saved">
          <RecordRow title="واحد" />
        </RecordList>
      </>,
    );

    expect(screen.getByRole("list", { name: "المحفوظة" })).toBeTruthy();
  });

  it("skips children that are not elements rather than rendering empty items", () => {
    render(
      <RecordList label="قائمة">
        {false}
        <RecordRow title="ظاهر" />
        {null}
      </RecordList>,
    );

    expect(screen.getAllByRole("listitem")).toHaveLength(1);
  });
});

describe("RecordRow", () => {
  it("titles the record with a heading at the level asked for", () => {
    const { rerender } = render(<RecordRow title="إعلان الاختبار" />);

    expect(screen.getByRole("heading", { level: 3, name: "إعلان الاختبار" })).toBeTruthy();

    rerender(<RecordRow title="إعلان الاختبار" level={4} />);

    expect(screen.getByRole("heading", { level: 4, name: "إعلان الاختبار" })).toBeTruthy();
  });

  it("paints the chip from TONE_CLASSES, so it can never name an undefined token", () => {
    const { container } = render(<RecordRow title="منتهٍ" Icon={BookIcon} tone="neutral" />);

    const chip = container.querySelector("[aria-hidden='true']");

    expect(chip?.className).toContain(TONE_CLASSES.neutral);
    expect(chip?.querySelector("svg")).toBeTruthy();
  });

  it("shows the status beside the title", () => {
    render(<RecordRow title="مسودّة المقال" status={<Badge tone="warning">مسودّة</Badge>} />);

    const heading = screen.getByRole("heading", { name: "مسودّة المقال" });

    expect(heading.parentElement?.textContent).toContain("مسودّة");
  });

  it("renders the meta as a definition list whose hidden labels still reach a screen reader", () => {
    render(
      <RecordRow
        title="إعلان"
        meta={[
          { key: "recipients", label: "المستلمون", value: "٢٤", Icon: UsersIcon },
          { key: "views", label: "المشاهدات", value: "١٢", labelHidden: true },
        ]}
      />,
    );

    const terms = screen.getAllByRole("term");
    const values = screen.getAllByRole("definition");

    expect(terms.map((term) => term.textContent)).toEqual(["المستلمون", "المشاهدات"]);
    expect(values.map((value) => value.textContent)).toEqual(["٢٤", "١٢"]);
    expect(screen.getByText("المشاهدات").className).toContain("sr-only");
    expect(screen.getByText("المستلمون").className).not.toContain("sr-only");
  });

  it("renders no meta list when there is nothing in it", () => {
    render(<RecordRow title="فارغ" meta={[]} />);

    expect(screen.queryByRole("term")).toBeNull();
  });

  it("renders the actions exactly once — an armed ConfirmButton must not have a twin", () => {
    render(
      <RecordRow
        title="واجب"
        actions={
          <>
            <button type="button">تعديل</button>
            <button type="button">حذف</button>
          </>
        }
      />,
    );

    expect(screen.getAllByRole("button", { name: "حذف" })).toHaveLength(1);
    expect(screen.getAllByRole("button", { name: "تعديل" })).toHaveLength(1);
  });

  it("with href, makes the title the link and keeps the actions outside it", () => {
    render(
      <RecordRow
        title="مقال عن الكسور"
        href="/manage/blog/abc"
        actions={<button type="button">أرشفة</button>}
      />,
    );

    const link = screen.getByRole("link", { name: "مقال عن الكسور" });

    expect(link.getAttribute("href")).toBe("/manage/blog/abc");
    expect(within(link).queryByRole("button")).toBeNull();
    // The overlay that makes the whole card a target hangs off the link.
    expect(link.className).toContain("after:absolute");
    expect(link.className).toContain("after:inset-0");
  });

  it("puts the full-width content after the row", () => {
    render(
      <RecordRow title="تركيبة">
        <p>المحرّر المفتوح</p>
      </RecordRow>,
    );

    expect(screen.getByText("المحرّر المفتوح")).toBeTruthy();
  });
});
