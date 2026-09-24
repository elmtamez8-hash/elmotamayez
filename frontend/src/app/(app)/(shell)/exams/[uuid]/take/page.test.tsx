import { act, fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import TakeExamPage from "./page";

/*
| An essay on a graded paper has somewhere to write, and what is written is SENT.
|
| The screen drew option buttons only, so an essay question rendered with no
| control under it and every submission carried `selected_option_ids: []` and no
| text at all — the teacher's grading board read «تركه الطالب بلا إجابة» about
| every essay on the platform.
*/

const post = vi.fn();
const push = vi.fn();

vi.mock("@/lib/api", () => ({
  api: { post: (...args: unknown[]) => post(...args) },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
}));

const ATTEMPT = {
  attempt: { uuid: "a-1", status: "in_progress" },
  questions: [
    { id: 10, type: "mcq", content: "كم يساوي ٢+٢؟", points: 1, options: [{ id: 100, content: "٤" }] },
    { id: 11, type: "essay", content: "اشرح قانون نيوتن الأول.", points: 5, options: [] },
  ],
};

beforeEach(() => {
  vi.clearAllMocks();
  post.mockImplementation((path: string) =>
    path.endsWith("/submit") ? Promise.resolve({ uuid: "a-1" }) : Promise.resolve(ATTEMPT),
  );
});

describe("taking an exam with an essay", () => {
  it("offers a text box for the essay and submits what was written", async () => {
    await act(async () => {
      render(<TakeExamPage params={Promise.resolve({ uuid: "e-1" })} />);
    });

    fireEvent.click(await screen.findByRole("button", { name: "٤" }));
    fireEvent.change(screen.getByLabelText("إجابتك"), {
      target: { value: "الجسم يبقى على حالته" },
    });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "سلّم الاختبار" }));
    });

    expect(post).toHaveBeenCalledWith("/attempts/a-1/submit", {
      answers: [
        { question_id: 10, selected_option_ids: [100] },
        { question_id: 11, selected_option_ids: [], answer_text: "الجسم يبقى على حالته" },
      ],
    });
  });

  it("sends an untouched essay as blank rather than dropping the question", async () => {
    await act(async () => {
      render(<TakeExamPage params={Promise.resolve({ uuid: "e-1" })} />);
    });

    await screen.findByLabelText("إجابتك");

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "سلّم الاختبار" }));
    });

    const [, body] = post.mock.calls.find(([path]) => String(path).endsWith("/submit"))!;
    expect(body.answers).toContainEqual({ question_id: 11, selected_option_ids: [], answer_text: null });
  });
});
