import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { compliance } from "@/lib/compliance";
import type { GuardianRelation } from "@/lib/notifications";

import { RelationRow } from "./RelationRow";

/*
 * Spec 013 — the guardian's half of the consent gate.
 *
 * ⚠️ `PUT /privacy/consents/categories` took a `student_uuid` and no screen ever
 * sent one, so a self-registered minor stayed locked out however willing their
 * guardian was: the notification led to `/family`, the guardian accepted the link,
 * and the page offered nothing more. These cases are the missing door.
 */
vi.mock("@/lib/compliance", () => ({
  compliance: { categories: vi.fn(), policy: vi.fn(), updateCategories: vi.fn() },
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: { data_subject_roles: ["parent"] } }),
}));

const categories = vi.mocked(compliance.categories);
const policy = vi.mocked(compliance.policy);
const updateCategories = vi.mocked(compliance.updateCategories);

function relation(overrides: Partial<GuardianRelation> = {}): GuardianRelation {
  return {
    uuid: "r-1",
    relation_type: "parent",
    relation_type_label: "وليّ أمر",
    status: "active",
    status_label: "نشطة",
    student_name: "كريم",
    student_age: 14,
    student_grade_level_slug: null,
    student_school_year_slug: null,
    student_school_year_name: null,
    student_has_account: true,
    viewer_side: "guardian",
    can_decide: false,
    accepted_at: null,
    student_uuid: "child-uuid",
    student_awaiting_consent: true,
    permissions: [{ key: "data_rights", label: "الموافقة على معالجة البيانات" }],
    revoked_at: null,
    created_at: null,
    ...overrides,
  } as GuardianRelation;
}

const noop = async () => undefined;

describe("RelationRow consent", () => {
  beforeEach(() => {
    categories.mockReset();
    policy.mockReset();
    updateCategories.mockReset();

    categories.mockResolvedValue({ data: [], processors: [] } as never);
    policy.mockResolvedValue({ version: "1.0", body_html: "<p>نصّ</p>" } as never);
    updateCategories.mockResolvedValue({ version: "1.0", categories: [] } as never);
  });

  it("lets the guardian consent FOR THE CHILD, and reloads once it lands", async () => {
    const onConsented = vi.fn();

    render(
      <RelationRow
        relation={relation()}
        onAccept={noop}
        onRevoke={noop}
        onSavePermissions={noop}
        onConsented={onConsented}
      />,
    );

    expect(screen.getByText("حساب ابنك بانتظار موافقتك")).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: "مراجعة الموافقة على معالجة البيانات" }));

    const agree = await screen.findByRole("button", { name: "أوافق على ما سبق" });
    await waitFor(() => expect((agree as HTMLButtonElement).disabled).toBe(false));
    fireEvent.click(agree);

    // The CHILD's uuid — without it the server records the guardian's own consent.
    await waitFor(() =>
      expect(updateCategories).toHaveBeenCalledWith({
        categories: [],
        version: "1.0",
        student_uuid: "child-uuid",
      }),
    );
    await waitFor(() => expect(onConsented).toHaveBeenCalled());
  });

  it("offers nothing when the server says no consent is awaited from this reader", () => {
    render(
      <RelationRow
        relation={relation({ student_awaiting_consent: false })}
        onAccept={noop}
        onRevoke={noop}
        onSavePermissions={noop}
      />,
    );

    expect(screen.queryByText("حساب ابنك بانتظار موافقتك")).toBeNull();
    expect(screen.queryByRole("button", { name: "مراجعة الموافقة على معالجة البيانات" })).toBeNull();
  });
});
