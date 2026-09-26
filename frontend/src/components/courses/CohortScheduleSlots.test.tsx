import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";

import { formatCohortSlot } from "@/lib/session-format";
import { setStoredViewerTimeZone } from "@/lib/viewer-time-zone";

import { CohortScheduleSlots } from "./CohortScheduleSlots";

/*
| A group's meeting slots on the VISITOR's clock (owner decision 2026-09-26).
| The platform-zone labels are what the server render shows; the instants are
| what the browser redraws on the reader's own zone.
*/

afterEach(() => setStoredViewerTimeZone(null));

const SATURDAY_FIVE_DOHA = "2026-11-07T14:00:00Z";

describe("CohortScheduleSlots", () => {
  it("draws a Doha group's slot on a Cairo visitor's clock, zone named", () => {
    setStoredViewerTimeZone("Africa/Cairo");

    render(<CohortScheduleSlots labels={["السبت 17:00"]} slots={[SATURDAY_FIVE_DOHA]} />);

    expect(screen.getByText(formatCohortSlot(SATURDAY_FIVE_DOHA, "Africa/Cairo"))).toBeTruthy();
    expect(screen.queryByText("السبت 17:00")).toBeNull();
    expect(screen.getByText("توقيت مصر")).toBeTruthy();
  });

  it("falls back to the labels when the payload carries no instants", () => {
    render(<CohortScheduleSlots labels={["السبت 17:00"]} />);

    expect(screen.getByText("السبت 17:00")).toBeTruthy();
  });

  it("says so in words when nothing is scheduled", () => {
    render(<CohortScheduleSlots labels={[]} slots={[]} />);

    expect(screen.getByText("لم تُجدول حصص بعد")).toBeTruthy();
  });
});
