import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { StudyRoomBoard } from "./StudyRoomBoard";
import type { StudyRoomBoard as Board } from "@/lib/study-rooms";

/*
| THE BOARD IS PUSHED, SO NOBODY NOTICES IT FIRST.
|
| Every other screen in this product has one reader who sees a defect and says so.
| This one is broadcast to everybody in the room at once — an empty name or a
| board that stopped updating looks the same to all of them, and each assumes it
| is their own connection. So the two things worth measuring are that a frame
| REPLACES the board, and that the subscription is released exactly once.
|
| ⚠️ AND ONLY A COMPONENT TEST CAN SEE EITHER. The backend proves the payload; it
| knows nothing about whether this component ever bound a listener to it.
*/

const release = vi.fn();
const listen = vi.fn();

vi.mock("@/lib/echo", () => ({
  listen: (...args: unknown[]) => listen(...args),
}));

const board: Board = {
  room_uuid: "room-1",
  ends_at: new Date(Date.now() + 120_000).toISOString(),
  rows: [{ uuid: "p-1", name: "سارة", score: 3, answered: 4 }],
};

/*
  ⚠️ `beforeEach`, NOT `afterEach`. Testing Library's automatic `cleanup` is
  registered by the setup file and vitest runs `afterEach` hooks in reverse
  registration order, so it unmounts the previous component AFTER a reset written
  here — and the release it triggers is then counted against the NEXT test. That
  is precisely the assertion below («exactly once») failing for a reason that has
  nothing to do with the component.
*/
beforeEach(() => {
  listen.mockReset();
  release.mockReset();
});

describe("StudyRoomBoard", () => {
  it("renders the rows it was handed", async () => {
    listen.mockResolvedValue(release);

    render(
      <StudyRoomBoard roomUuid="room-1" initial={board} questionCount={10} state="live" />,
    );

    expect(screen.getByText("سارة")).toBeTruthy();
    // ⚠️ The NAME, not the row count. `->with('user:id,uuid,name')` selects a
    // column `users` does not have — it is an accessor over `first_name` and
    // `last_name` — and every row then renders as an empty string with the count
    // still right. That spelling has shipped six times in this tree.
    expect(screen.getByText("سارة").textContent).not.toBe("");
    expect(screen.getByText(/4\/10/)).toBeTruthy();

    await waitFor(() => expect(listen).toHaveBeenCalled());
  });

  it("subscribes to the room's own channel and replaces the board from a frame", async () => {
    listen.mockResolvedValue(release);

    render(
      <StudyRoomBoard roomUuid="room-1" initial={board} questionCount={10} state="live" />,
    );

    await waitFor(() => expect(listen).toHaveBeenCalled());

    const [channel, event, handler] = listen.mock.calls[0] as [
      string,
      string,
      (payload: Board) => void,
    ];

    // Named once in `lib/study-rooms.ts` and once in `routes/channels.php`; a
    // third spelling here is how the two drift apart.
    expect(channel).toBe("study-room-board.room-1");
    expect(event).toBe("board.updated");

    handler({
      ...board,
      rows: [
        { uuid: "p-2", name: "خالد", score: 9, answered: 10 },
        { uuid: "p-1", name: "سارة", score: 3, answered: 4 },
      ],
    });

    await waitFor(() => expect(screen.getByText("خالد")).toBeTruthy());
    expect(screen.getByText(/10\/10/)).toBeTruthy();
  });

  it("releases the subscription once on unmount", async () => {
    listen.mockResolvedValue(release);

    const view = render(
      <StudyRoomBoard roomUuid="room-1" initial={board} questionCount={10} state="live" />,
    );

    await waitFor(() => expect(listen).toHaveBeenCalled());

    view.unmount();

    /*
      ⚠️ EXACTLY ONCE. pusher-js unbinds BY FUNCTION REFERENCE and drops every
      entry matching it, and `leave()` is refcounted per channel — so a cleanup
      that ran twice would decrement the count for a listener somebody else still
      holds, and silence a live subscription with nothing saying why.
    */
    await waitFor(() => expect(release).toHaveBeenCalledTimes(1));
  });

  it("shows the room as over when the server says so, whatever this clock says", () => {
    listen.mockResolvedValue(release);

    render(
      // `ends_at` is still in the future here ON PURPOSE: the state is the
      // server's answer, and a browser a minute out must not overrule it.
      <StudyRoomBoard roomUuid="room-1" initial={board} questionCount={10} state="closed" />,
    );

    expect(screen.getByText("انتهى الوقت")).toBeTruthy();
  });
});
