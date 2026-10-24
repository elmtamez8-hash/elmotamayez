import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { TeacherCard } from "@/lib/public-api";
import { HeroSpotlight } from "./HeroSpotlight";

/*
| The home hero's slider: no single teacher owns the card (owner, 2026-10-23),
| so the arrows and the row of faces must actually change who it shows.
*/

function teacher(n: number): TeacherCard {
  return {
    uuid: `t-${n}`,
    slug: `teacher-${n}`,
    name: `مدرّس ${n}`,
    headline: null,
    photo_url: null,
    subjects: [],
    grade_levels: [],
    years_experience: 3,
    teaching_languages: [],
    average_rating: null,
    reviews_count: 0,
    trust_score: null,
    trust_score_band: "building",
    is_verified: false,
    available_now: false,
  };
}

const shown = () => screen.getByRole("heading", { level: 2 }).textContent;

describe("HeroSpotlight", () => {
  it("opens on the first teacher and walks both ways with the arrows, wrapping", () => {
    render(<HeroSpotlight teachers={[teacher(1), teacher(2), teacher(3)]} total={8} />);

    expect(shown()).toBe("مدرّس 1");
    fireEvent.click(screen.getByRole("button", { name: "المدرّس التالي" }));
    expect(shown()).toBe("مدرّس 2");
    fireEvent.click(screen.getByRole("button", { name: "المدرّس السابق" }));
    fireEvent.click(screen.getByRole("button", { name: "المدرّس السابق" }));
    expect(shown()).toBe("مدرّس 3");
  });

  it("shows the teacher whose face was pressed and links to their profile", () => {
    render(<HeroSpotlight teachers={[teacher(1), teacher(2), teacher(3)]} total={8} />);

    fireEvent.click(screen.getByRole("button", { name: "اعرض مدرّس 3" }));

    expect(shown()).toBe("مدرّس 3");
    expect(screen.getByRole("link", { name: "عرض ملف المدرّس" }).getAttribute("href")).toBe("/teachers/teacher-3");
  });

  it("offers no controls when there is only one teacher", () => {
    render(<HeroSpotlight teachers={[teacher(1)]} total={1} />);

    expect(screen.queryByRole("button")).toBeNull();
  });
});
