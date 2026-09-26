import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

/*
| «اشترِ» on /plans is a LINK to /subscribe — never a purchase call of its own.
|
| ⛔ It used to call `plans.buy`, which posted `{ plan_uuid }` alone; since
| 2026-09-05 `mode` is required on `POST /billing/subscriptions`, so every press
| was refused with 422 and no test here said so, because there was no test here.
| These assert the target for each plan type, and that no request is made.
*/

const COURSE = "course-uuid";

const PRIVATE_PLAN = {
  uuid: "plan-private",
  title: "ساعات فردية",
  duration_days: 30,
  session_count: null,
  session_type: "individual",
  coverage_type: "course",
  coverage_label: "هذا الكورس",
  coverage_uuid: COURSE,
  price_minor: 30_000,
  currency: "QAR",
  is_active: true,
  is_sellable: true,
};

const GROUP_PLAN = {
  ...PRIVATE_PLAN,
  uuid: "plan-group",
  title: "المجموعة الشهرية",
  session_type: "group",
};

const COHORT_PLAN = {
  ...GROUP_PLAN,
  uuid: "plan-cohort",
  title: "مجموعة السبت",
  coverage_type: "cohort",
  coverage_uuid: "cohort-uuid",
};

const post = vi.fn();

vi.mock("@/lib/api", () => ({ api: { post: (...args: unknown[]) => post(...args) } }));

const ONE_BALANCE = [
  { uuid: "b-1", course: { uuid: COURSE, title: "الرياضيات", teacher_name: "أ. سالم" } },
];

/* ما يردُّه `/billing/balance` — فارغٌ لطالبٍ جديدٍ لم يشترِ شيئاً بعد. */
let mockBalances: unknown[] = ONE_BALANCE;

vi.mock("@/lib/billing", () => ({
  billing: {
    balances: () => Promise.resolve({ data: mockBalances }),
  },
}));

/* الـslug الذي يردُّه الكورس — `null` يحاكي قراءةً أخفقت. */
let mockSlug: string | null = "math-basics";

vi.mock("@/lib/subscribe", () => ({
  subscribe: {
    course: () =>
      mockSlug === null
        ? Promise.reject(new Error("offline"))
        : Promise.resolve({ data: { uuid: COURSE, slug: mockSlug } }),
  },
}));

vi.mock("@/lib/plans", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/plans")>();

  return {
    ...actual,
    plans: {
      ...actual.plans,
      mine: () => Promise.resolve({ data: [] }),
      forCourse: () => Promise.resolve({ data: [PRIVATE_PLAN, GROUP_PLAN, COHORT_PLAN] }),
    },
  };
});

import PlansPage from "./page";

afterEach(() => {
  cleanup();
  post.mockReset();
  mockBalances = ONE_BALANCE;
  mockSlug = "math-basics";
});

async function offersFor(title: string): Promise<HTMLElement> {
  render(<PlansPage />);

  fireEvent.change(await screen.findByLabelText("اختر الكورس"), { target: { value: COURSE } });

  const row = (await screen.findByText(title)).closest("div.rounded-xl");

  if (!(row instanceof HTMLElement)) throw new Error(`no row for ${title}`);

  return row;
}

function buyLink(row: HTMLElement): string | null {
  const link = Array.from(row.querySelectorAll("a")).find((a) => a.textContent?.trim() === "اشترِ");

  return link?.getAttribute("href") ?? null;
}

describe("/plans «اشترِ»", () => {
  it("sends a private plan to /subscribe in private mode, carrying the plan", async () => {
    const row = await offersFor(PRIVATE_PLAN.title);

    expect(buyLink(row)).toBe(`/subscribe?course=${COURSE}&mode=private&plan=plan-private`);
  });

  it("sends a group plan to the course page's groups tab, by its slug", async () => {
    // /subscribe with no `cohort` opens in PRIVATE mode and filters group plans
    // out — so the only honest target without a group is the course page. And
    // the uuid address 308s to the slug and drops `?tab=`, so the slug it is.
    const row = await offersFor(GROUP_PLAN.title);

    await waitFor(() => expect(buyLink(row)).toBe("/courses/math-basics?tab=groups"));
  });

  it("falls back to the uuid with the fragment when the slug could not be read", async () => {
    mockSlug = null;

    const row = await offersFor(GROUP_PLAN.title);

    expect(buyLink(row)).toBe(`/courses/${COURSE}?tab=groups#groups`);
  });

  it("sends a plan written for one group straight to /subscribe with that group", async () => {
    const row = await offersFor(COHORT_PLAN.title);

    expect(buyLink(row)).toBe(`/subscribe?course=${COURSE}&cohort=cohort-uuid&plan=plan-cohort`);
  });

  it("makes no purchase request of its own", async () => {
    const row = await offersFor(PRIVATE_PLAN.title);

    fireEvent.click(row.querySelector("a") as HTMLAnchorElement);

    expect(post).not.toHaveBeenCalled();
  });
});

/*
| بلاغُ ٢٠٢٦-٠٩-٢٦ — طالبٌ جديدٌ رأى «اختر كورساً» وحدَها تحتَ جملةٍ تقولُ «اختر
| مدرّساً من القائمة أدناه»: القائمةُ مبنيّةٌ من أرصدتِه، وليسَ له رصيدٌ بعد.
*/
describe("/plans for a student with no teacher yet", () => {
  it("offers the way to a teacher instead of an empty picker", async () => {
    mockBalances = [];

    render(<PlansPage />);

    expect(await screen.findByText("لم تبدأ مع أي مدرّس بعد")).toBeDefined();
    expect(screen.queryByLabelText("اختر الكورس")).toBeNull();
    expect(screen.getByRole("link", { name: "تصفّح المدرّسين" }).getAttribute("href")).toBe(
      "/teachers",
    );
    expect(screen.getByRole("link", { name: "تصفّح الكورسات" }).getAttribute("href")).toBe(
      "/courses",
    );
    // No sentence pointing at a list that is not there.
    expect(screen.queryByText(/من القائمة أدناه/)).toBeNull();
  });
});
