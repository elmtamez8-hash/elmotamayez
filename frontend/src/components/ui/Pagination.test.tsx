import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Pagination, pageWindow } from "./Pagination";

describe("pageWindow", () => {
  it("keeps the first, the last and the neighbours, and draws a gap of one as its page", () => {
    expect(pageWindow(1, 1)).toEqual([1]);
    expect(pageWindow(1, 5)).toEqual([1, 2, null, 5]);
    expect(pageWindow(3, 5)).toEqual([1, 2, 3, 4, 5]);
    expect(pageWindow(6, 12)).toEqual([1, null, 5, 6, 7, null, 12]);
    expect(pageWindow(12, 12)).toEqual([1, null, 11, 12]);
  });
});

describe("Pagination", () => {
  it("draws nothing for a single page", () => {
    const { container } = render(<Pagination basePath="/store" currentPage={1} lastPage={1} searchParams={{}} />);

    expect(container.innerHTML).toBe("");
  });

  it("keeps the filters, drops page 1 from the url, and marks the current page", () => {
    render(<Pagination basePath="/courses" currentPage={2} lastPage={3} searchParams={{ subject: "math", page: "2" }} />);

    expect(screen.getByRole("link", { name: "السابق" }).getAttribute("href")).toBe("/courses?subject=math");
    expect(screen.getByRole("link", { name: "التالي" }).getAttribute("href")).toBe("/courses?subject=math&page=3");
    expect(screen.getByRole("link", { name: "الصفحة ٢" }).getAttribute("aria-current")).toBe("page");
  });

  it("pages one list of a page by its own parameter and returns the reader to it", () => {
    // The teacher's reviews: `?tab=reviews&reviews_page=2#reviews-list`.
    render(
      <Pagination
        basePath="/teachers/ahmed"
        currentPage={1}
        lastPage={5}
        searchParams={{ tab: "reviews" }}
        pageParam="reviews_page"
        hash="reviews-list"
      />,
    );

    expect(screen.getByRole("link", { name: "التالي" }).getAttribute("href")).toBe(
      "/teachers/ahmed?tab=reviews&reviews_page=2#reviews-list",
    );
    // No previous page: the end keeps its place and is not a link.
    expect(screen.queryByRole("link", { name: "السابق" })).toBeNull();
  });
});
