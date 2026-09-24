import { render } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { SeatBadge } from "./SeatBadge";

/*
| «5 من 5 مقعد متاح» — production, 2026-09-24, on a group's session card. The
| noun agreed with neither number; it agrees with the count of free seats now,
| and the total follows it.
*/
describe("SeatBadge", () => {
  it.each([
    [1, "مقعد واحد متاح من ٥٠"],
    [2, "مقعدان متاحان من ٥٠"],
    [3, "٣ مقاعد متاحة من ٥٠"],
    [11, "١١ مقعداً متاحاً من ٥٠"],
  ])("%i free", (available, text) => {
    const { container } = render(<SeatBadge seats={{ available, total: 50, taken: 50 - available }} />);

    expect(container.textContent).toBe(text);
  });

  it("says a full session in words", () => {
    const { container } = render(<SeatBadge seats={{ available: 0, total: 5, taken: 5 }} />);

    expect(container.textContent).toBe("اكتملت المقاعد");
  });
});
