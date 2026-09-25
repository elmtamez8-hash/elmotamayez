import { cleanup, fireEvent, render, screen } from "@testing-library/react";
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

vi.mock("@/lib/billing", () => ({
  billing: {
    balances: () =>
      Promise.resolve({
        data: [{ uuid: "b-1", course: { uuid: COURSE, title: "الرياضيات", teacher_name: "أ. سالم" } }],
      }),
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
  it("sends a private plan to /subscribe in private mode", async () => {
    const row = await offersFor(PRIVATE_PLAN.title);

    expect(buyLink(row)).toBe(`/subscribe?course=${COURSE}&mode=private`);
  });

  it("sends a group plan to the course page, where the group is chosen", async () => {
    // /subscribe with no `cohort` opens in PRIVATE mode and filters group plans
    // out — so the only honest target without a group is the course page.
    const row = await offersFor(GROUP_PLAN.title);

    expect(buyLink(row)).toBe(`/courses/${COURSE}`);
  });

  it("sends a plan written for one group straight to /subscribe with that group", async () => {
    const row = await offersFor(COHORT_PLAN.title);

    expect(buyLink(row)).toBe(`/subscribe?course=${COURSE}&cohort=cohort-uuid`);
  });

  it("makes no purchase request of its own", async () => {
    const row = await offersFor(PRIVATE_PLAN.title);

    fireEvent.click(row.querySelector("a") as HTMLAnchorElement);

    expect(post).not.toHaveBeenCalled();
  });
});
