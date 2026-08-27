import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { beforeEach, describe, expect, it } from "vitest";

import { Tabs, TabPanel, useTabParam, type TabDefinition } from "./Tabs";

/*
| ⚠️ NO BACKEND TEST REACHES ANY OF THIS. Whether the arrow keys walk a tab strip
| the right way in an RTL product, whether `?tab=` survives a reload, and whether
| Tab leaves the strip instead of walking six tabs — the API knows about none of
| them, and Playwright builds for production and needs two servers, so nobody
| runs it inside a development loop. `npm test` needs neither.
|
| ⚠️ AND THE DIRECTION IS THE ONE THAT SHIPS WRONG. `ArrowRight = next` is what
| every LTR example on the internet says and what a reader typing it from memory
| writes; in this product it walks a keyboard user BACKWARDS past the tab they
| started on, silently, on every screen that uses the component.
*/

const TABS: TabDefinition[] = [
  { key: "curriculum", label: "المنهج" },
  { key: "sessions", label: "الحصص" },
  { key: "exams", label: "الاختبارات", badge: 3 },
];

function Harness({ initial = "curriculum" }: { initial?: string }) {
  const [active, setActive] = useState(initial);

  return (
    <>
      <Tabs tabs={TABS} active={active} onChange={setActive} label="أقسام المادّة" />
      <TabPanel tabKey="curriculum" active={active}>
        محتوى المنهج
      </TabPanel>
      <TabPanel tabKey="sessions" active={active}>
        محتوى الحصص
      </TabPanel>
      <TabPanel tabKey="exams" active={active}>
        محتوى الاختبارات
      </TabPanel>
    </>
  );
}

/** The hook on its own, so the URL half is measured without the strip. */
function ParamHarness() {
  const [active, select] = useTabParam(TABS);

  return (
    <>
      <span data-testid="active">{active}</span>
      <button type="button" onClick={() => select("exams")}>
        افتح الاختبارات
      </button>
    </>
  );
}

beforeEach(() => {
  window.history.replaceState(null, "", "/enrollments/c-1");
});

describe("Tabs", () => {
  it("walks the strip leftwards with ArrowLeft, because the strip runs leftwards", () => {
    render(<Harness />);

    const strip = screen.getByRole("tablist");

    fireEvent.keyDown(strip, { key: "ArrowLeft" });
    expect(screen.getByRole("tab", { name: "الحصص" }).getAttribute("aria-selected")).toBe("true");

    fireEvent.keyDown(strip, { key: "ArrowRight" });
    expect(screen.getByRole("tab", { name: "المنهج" }).getAttribute("aria-selected")).toBe("true");
  });

  it("wraps at both ends and answers Home and End", () => {
    render(<Harness />);

    const strip = screen.getByRole("tablist");

    // One step back from the first lands on the last, rather than doing nothing
    // — a strip that stops at its edges strands a reader who overshot.
    fireEvent.keyDown(strip, { key: "ArrowRight" });
    expect(screen.getByRole("tab", { name: /الاختبارات/ }).getAttribute("aria-selected")).toBe("true");

    fireEvent.keyDown(strip, { key: "Home" });
    expect(screen.getByRole("tab", { name: "المنهج" }).getAttribute("aria-selected")).toBe("true");

    fireEvent.keyDown(strip, { key: "End" });
    expect(screen.getByRole("tab", { name: /الاختبارات/ }).getAttribute("aria-selected")).toBe("true");
  });

  it("keeps exactly one tab in the tab order", () => {
    render(<Harness />);

    const inOrder = screen
      .getAllByRole("tab")
      .filter((tab) => tab.getAttribute("tabindex") === "0");

    expect(inOrder).toHaveLength(1);
    expect(inOrder[0].textContent).toContain("المنهج");
  });

  it("shows only the selected panel, named by its tab", () => {
    render(<Harness initial="sessions" />);

    const panel = screen.getByRole("tabpanel");

    expect(panel.textContent).toBe("محتوى الحصص");
    expect(panel.getAttribute("aria-labelledby")).toBe("tab-sessions");
    expect(screen.queryByText("محتوى المنهج")).toBeNull();
  });
});

describe("useTabParam", () => {
  it("opens on the tab named in the address bar", () => {
    window.history.replaceState(null, "", "/enrollments/c-1?tab=exams");

    render(<ParamHarness />);

    expect(screen.getByTestId("active").textContent).toBe("exams");
  });

  it("falls back to the first tab when the name is unknown, rather than showing nothing", () => {
    window.history.replaceState(null, "", "/enrollments/c-1?tab=nonsense");

    render(<ParamHarness />);

    expect(screen.getByTestId("active").textContent).toBe("curriculum");
  });

  /*
   | ⚠️ `replaceState`, NOT `push`. A tab is a view of one page: pushing means the
   | back button walks a student back through every tab they opened before it
   | leaves the course — and on a phone that is the button they use to leave.
   */
  it("writes the tab into the address bar without adding a history entry", () => {
    const before = window.history.length;

    render(<ParamHarness />);
    fireEvent.click(screen.getByRole("button", { name: "افتح الاختبارات" }));

    expect(new URLSearchParams(window.location.search).get("tab")).toBe("exams");
    expect(window.history.length).toBe(before);
  });
});
