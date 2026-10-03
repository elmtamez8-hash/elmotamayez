import { describe, expect, it } from "vitest";

import { decodePoll, encodePoll, newPollId, percent, readVote, tally, voteValue, type Poll } from "@/lib/live-poll";

const POLL: Poll = { v: 1, id: "abcd1234", question: "ناتج ٢+٢؟", options: ["٣", "٤", "٥"], state: "open" };

describe("live poll", () => {
  it("travels as a message and comes back the same", () => {
    expect(decodePoll(encodePoll(POLL))).toEqual({ ...POLL, counts: undefined });
    expect(decodePoll(encodePoll({ ...POLL, state: "closed", counts: [1, 5, 0] }))?.counts).toEqual([1, 5, 0]);
    expect(newPollId()).toMatch(/^[a-z0-9]{8}$/);
  });

  it("refuses anything that is not exactly a poll", () => {
    const bad = (value: unknown) => decodePoll(new TextEncoder().encode(JSON.stringify(value)));
    expect(decodePoll(new TextEncoder().encode("not json"))).toBeNull();
    expect(bad({ ...POLL, v: 2 })).toBeNull();
    expect(bad({ ...POLL, options: ["one"] })).toBeNull();
    expect(bad({ ...POLL, options: ["a", " "] })).toBeNull();
    expect(bad({ ...POLL, options: Array(7).fill("x") })).toBeNull();
    expect(bad({ ...POLL, state: "won" })).toBeNull();
    expect(bad({ ...POLL, counts: [1, 2] })).toBeNull();
    expect(bad({ ...POLL, counts: [1, -2, 0] })).toBeNull();
    expect(bad({ ...POLL, question: "x".repeat(201) })).toBeNull();
  });

  it("counts a vote only for this poll and a choice it has", () => {
    expect(readVote(voteValue(POLL.id, 1), POLL)).toBe(1);
    expect(readVote(voteValue("zzzz9999", 1), POLL)).toBeNull();
    expect(readVote(voteValue(POLL.id, 3), POLL)).toBeNull();
    expect(readVote("abcd1234:1<script>", POLL)).toBeNull();
    expect(readVote(undefined, POLL)).toBeNull();

    const voters = tally(POLL, [
      ["u1", "abcd1234:1"],
      ["u2", "abcd1234:0"],
      ["u3", "abcd1234:1"],
      ["u4", "old00000:2"],
      ["u5", undefined],
    ]);
    expect(voters).toEqual([["u2"], ["u1", "u3"], []]);
    expect(percent(2, 3)).toBe(67);
    expect(percent(0, 0)).toBe(0);
  });
});
