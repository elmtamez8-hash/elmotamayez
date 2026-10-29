import { describe, expect, it } from "vitest";

import { untilLabel } from "./UpcomingSessionsCard";

/*
| QA 2026-10-29: a session a week away read «يبدأ بعد 174 س و11 د». Past a whole
| day the countdown speaks in days.
*/
describe("untilLabel", () => {
  it("speaks in days past a whole day, with the hours left over", () => {
    expect(untilLabel(7 * 86400 + 6 * 3600 + 11 * 60)).toBe("يبدأ بعد 7 أيام و6 س");
    expect(untilLabel(86400)).toBe("يبدأ بعد يوم واحد");
  });

  it("keeps hours and minutes under a day", () => {
    expect(untilLabel(2 * 3600 + 15 * 60)).toBe("يبدأ بعد 2 س و15 د");
  });
});
