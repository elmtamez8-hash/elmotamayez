import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { RecordingNotice } from "./RecordingNotice";

/**
 * FR-013 — the room announces the recording, in the words the consent used.
 *
 * ⚠️ THIS IS A TEST ABOUT WORDING, AND THAT IS THE REQUIREMENT. A parent consented
 * to «الظهور في تسجيلات الحصص (‏صوتاً وصورةً)». A room that then says "this session
 * may be archived" describes the same thing in words nobody agreed to — the
 * consent stops covering what actually happens, and no type checker or backend
 * assertion can see that.
 */
describe("RecordingNotice", () => {
  it("names voice and image, matching the consent category", () => {
    render(<RecordingNotice />);

    const text = document.body.textContent ?? "";

    expect(text).toContain("تُسجَّل");
    // The two words the consent screen uses. Either one missing means the notice
    // and the consent are describing different things.
    expect(text).toContain("صوتُك");
    expect(text).toContain("صورتُك");
  });

  /*
   * ⚠️ AND IT SAYS WHAT THE PARTICIPANT CAN DO ABOUT IT.
   *
   * An announcement with no remedy is a warning, not a choice — and the whole
   * reason to announce BEFORE recording starts is that the person can still
   * decide not to appear. Saying only "you are being recorded" leaves them with
   * the information and no action, which is the shape of a notice that exists to
   * protect us rather than them.
   */
  it("tells the participant how not to appear", () => {
    render(<RecordingNotice />);

    const text = document.body.textContent ?? "";

    expect(text).toContain("أغلِقِ الكاميرا");
    // And that doing so does not exclude them from the class.
    expect(text).toContain("بالكتابة");
  });

  /*
   * ⚠️ AND IT NAMES WHO RECEIVES IT.
   *
   * "This is recorded" without "and everyone who booked this session can watch it"
   * is the half of the fact that matters least. A student picturing a private
   * archive behaves differently from one picturing their classmates.
   */
  it("says who the recording reaches", () => {
    render(<RecordingNotice />);

    expect(document.body.textContent ?? "").toContain("كلّ من حجز");
  });
});
