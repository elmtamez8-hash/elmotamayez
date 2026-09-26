import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { User } from "@/lib/types";
import { formatSessionClock } from "@/lib/session-format";
import { setStoredViewerTimeZone, useViewerTimeZone } from "@/lib/viewer-time-zone";

import { TimezoneCard, timezoneOptions } from "./TimezoneCard";

/*
| «منطقتي الزمنية» (owner decision 2026-09-26): Qatar and Egypt first, a choice
| is saved as `manual`, and every time on the screen moves to it at once.
*/

const setTimezone = vi.fn();
const refreshUser = vi.fn(() => Promise.resolve());
let currentUser: Partial<User> | null = null;

vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  auth: { setTimezone: (zone: string, source: string) => setTimezone(zone, source) },
}));

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ user: currentUser, refreshUser }),
}));

/** A time on the screen, drawn the way every session screen draws one. */
function Clock() {
  return <p data-testid="clock">{formatSessionClock("2026-11-03T15:00:00Z", useViewerTimeZone())}</p>;
}

beforeEach(() => {
  vi.clearAllMocks();
  currentUser = { uuid: "u1", timezone: "Asia/Qatar", timezone_source: null } as Partial<User>;
  setStoredViewerTimeZone("Asia/Qatar");
  setTimezone.mockResolvedValue({ uuid: "u1", timezone: "Africa/Cairo", timezone_source: "manual" });
});

afterEach(() => setStoredViewerTimeZone(null));

describe("timezoneOptions", () => {
  it("puts Qatar and Egypt first, then the Arabic-named zones, then the rest under their IANA names", () => {
    const options = timezoneOptions([
      "America/Argentina/Salta",
      "Africa/Cairo",
      "Europe/London",
      "Asia/Riyadh",
      "Asia/Qatar",
      "Asia/Amman",
    ]);

    expect(options.map((o) => o.value)).toEqual([
      "Asia/Qatar",
      "Africa/Cairo",
      // «الأردن» · «السعودية» · «المملكة المتحدة» — Arabic alphabetical order.
      "Asia/Amman",
      "Asia/Riyadh",
      "Europe/London",
      "America/Argentina/Salta",
    ]);
    expect(options[0].label).toBe("قطر — الدوحة");
    expect(options[1].label).toBe("مصر — القاهرة");
    expect(options[4].label).toBe("المملكة المتحدة — لندن");
    expect(options[5].label).toBe("America/Argentina/Salta");
  });

  it("names every Arab-world zone the runtime knows in Arabic", () => {
    const arab = [
      "Asia/Qatar", "Africa/Cairo", "Asia/Riyadh", "Asia/Dubai", "Asia/Kuwait", "Asia/Bahrain",
      "Asia/Muscat", "Asia/Baghdad", "Asia/Amman", "Asia/Damascus", "Asia/Beirut", "Asia/Gaza",
      "Asia/Aden", "Africa/Khartoum", "Africa/Tripoli", "Africa/Tunis", "Africa/Algiers",
      "Africa/Casablanca", "Africa/Nouakchott",
    ];

    for (const option of timezoneOptions(arab)) {
      expect(option.label).not.toMatch(/[A-Za-z]/);
    }
  });
});

describe("TimezoneCard", () => {
  it("saves the choice as MANUAL and redraws every time on it at once", async () => {
    render(
      <>
        <TimezoneCard />
        <Clock />
      </>,
    );

    const doha = screen.getByTestId("clock").textContent;

    fireEvent.change(screen.getByLabelText("منطقتي الزمنية"), { target: { value: "Africa/Cairo" } });
    fireEvent.click(screen.getByRole("button", { name: "احفظ المنطقة" }));

    await waitFor(() => expect(setTimezone).toHaveBeenCalledWith("Africa/Cairo", "manual"));
    await waitFor(() => expect(screen.getByTestId("clock").textContent).not.toBe(doha));

    // 15:00Z in November: 18:00 in Doha, 17:00 in Cairo.
    expect(screen.getByTestId("clock").textContent).toBe(formatSessionClock("2026-11-03T15:00:00Z", "Africa/Cairo"));
    expect(await screen.findByText("حُفِظت منطقتك الزمنية.")).toBeTruthy();
  });
});
