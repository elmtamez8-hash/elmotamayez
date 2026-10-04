import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { blankGraph, GRAPH_COLORS } from "@/lib/whiteboard/graph";
import { WB } from "@/lib/whiteboard/strings";

import { GraphEditor } from "./GraphEditor";

describe("GraphEditor", () => {
  it("hands back the functions in their colours, the ranges, and only the points with both numbers", () => {
    const onSave = vi.fn();
    render(<GraphEditor initial={blankGraph()} saving={false} onSave={onSave} onClose={vi.fn()} />);

    fireEvent.click(screen.getByRole("button", { name: WB.graph.addFn }));
    fireEvent.change(screen.getByLabelText(WB.graph.fn(2)), { target: { value: "sin(x)" } });
    fireEvent.click(screen.getByRole("checkbox", { name: WB.graph.yAuto }));
    fireEvent.click(screen.getByRole("checkbox", { name: WB.graph.degrees }));
    fireEvent.click(screen.getByRole("button", { name: WB.graph.addPoint }));
    fireEvent.click(screen.getByRole("button", { name: WB.graph.addPoint }));
    fireEvent.change(screen.getByLabelText(WB.graph.pointX(1)), { target: { value: "2" } });
    fireEvent.change(screen.getByLabelText(WB.graph.pointY(1)), { target: { value: "1" } });
    fireEvent.change(screen.getByLabelText(WB.graph.pointLabel(1)), { target: { value: "أ" } });
    fireEvent.click(screen.getByRole("button", { name: WB.graph.save }));

    expect(onSave.mock.calls[0][0]).toEqual({
      kind: "graph",
      v: 1,
      functions: [
        { expr: "x^2 - 3", color: GRAPH_COLORS[0] },
        { expr: "sin(x)", color: GRAPH_COLORS[1] },
      ],
      x: [-5, 5],
      y: [-5, 5],
      points: [{ x: 2, y: 1, label: "أ" }],
      angle: "deg",
    });
  });
});
