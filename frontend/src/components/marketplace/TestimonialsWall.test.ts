import { describe, expect, it } from "vitest";

import { arrangeWall } from "./TestimonialsWall";

const long = (tag: string, extra = 0) => `${tag} ${"ممتاز جدًا ".repeat(9 + extra)}`;
const review = (comment: string, rating = 5) => ({ comment, rating });

describe("arrangeWall", () => {
  it("features the longest long five-star quote and moves it first", () => {
    const items = [review("كويس"), review(long("أ")), review(long("ب", 3)), review("حلو", 4), review("تمام"), review("جميل")];

    const { ordered, featured } = arrangeWall(items);

    expect(featured).toBe(true);
    expect(ordered[0].comment.startsWith("ب")).toBe(true);
    expect(ordered).toHaveLength(6);
  });

  it("features nothing when no quote earns the large type", () => {
    const items = [review("كويس"), review(long("أ"), 4), review("حلو")];

    expect(arrangeWall(items)).toEqual({ ordered: items, featured: false });
  });

  it("features nothing when the count would leave holes in the grid", () => {
    const items = [review(long("أ")), review("كويس"), review("حلو"), review("تمام")];

    expect(arrangeWall(items).featured).toBe(false);
  });
});
