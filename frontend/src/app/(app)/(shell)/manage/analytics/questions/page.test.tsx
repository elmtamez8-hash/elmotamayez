import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| The search box narrows the IDEAS only. The question list is the server's first
| page and its endpoint takes no query, so a box over it would say «no match»
| about a question on page two; the idea list arrives whole. Pinned: the box
| filters the idea rows by name or lesson, a miss offers «مسح البحث»,
| and the question total comes from `meta`, not from the rows on screen.
*/
const questions = vi.fn();
const concepts = vi.fn();

vi.mock("@/lib/analytics", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/analytics")>();

  return { ...actual, analytics: { questions: () => questions(), concepts: () => concepts() } };
});

const { default: ItemAnalysisPage } = await import("./page");

const computed_at = "2026-09-20T02:00:00Z";

function concept(uuid: string, name: string, lesson: string | null) {
  return {
    concept: { uuid, name },
    is_overall: lesson === null,
    lesson: lesson === null ? null : { uuid: `${uuid}-l`, title: lesson },
    attempts_count: 12,
    wrong_count: 6,
    wrong_pct: 50,
    has_enough_data: true,
    computed_at,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  questions.mockResolvedValue({
    data: [
      {
        question: { uuid: "q-1", content: "ما ناتج ٢ + ٢؟", is_active: true, concept: null },
        attempts_count: 30,
        wrong_count: 3,
        wrong_pct: 10,
        has_enough_data: true,
        computed_at,
      },
    ],
    meta: { total: 40, current_page: 1, last_page: 2, scope: "workspace" },
  });
  concepts.mockResolvedValue({
    data: [concept("c-1", "الكسور", null), concept("c-2", "المعادلات", "درس الجبر")],
    meta: { scope: "workspace" },
  });
});

async function openPage() {
  await act(async () => {
    render(<ItemAnalysisPage />);
  });
}

describe("the item-analysis screen", () => {
  it("offers the search over the ideas only", async () => {
    await openPage();

    expect(screen.getAllByRole("searchbox")).toHaveLength(1);
    expect(screen.getByLabelText("ابحث في الأفكار")).toBeTruthy();
  });

  it("narrows the ideas by the lesson they sit in, too", async () => {
    await openPage();

    fireEvent.change(screen.getByLabelText("ابحث في الأفكار"), { target: { value: "الجبر" } });

    expect(screen.queryByText("الكسور")).toBeNull();
    expect(screen.getByText("المعادلات")).toBeTruthy();
  });

  it("offers to clear a search that matched nothing", async () => {
    await openPage();

    fireEvent.change(screen.getByLabelText("ابحث في الأفكار"), { target: { value: "هندسة" } });
    expect(screen.getByText("لا فكرة تطابق البحث")).toBeTruthy();

    // Two of that name: the box's own «×» and the empty state's action. Press the latter.
    const clears = screen.getAllByRole("button", { name: "مسح البحث" });
    fireEvent.click(clears[clears.length - 1]);
    expect(screen.getByText("الكسور")).toBeTruthy();
  });

  it("tells the question total from the server, not from the page on screen", async () => {
    await openPage();

    expect(screen.getByText(/يُعرض أدناه أعلاها خطأً: ١\./)).toBeTruthy();
  });
});
