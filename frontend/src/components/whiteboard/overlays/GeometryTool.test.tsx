import { fireEvent, render } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { distance, type Point } from "@/lib/whiteboard/geometry";

import { GeometryTool, OverlayLayer, type View } from "./GeometryTool";

/*
| Spec 039 · US9 (T121, FR-029). The instrument draws ONLY through `onDraw` — what
| it hands over becomes an ordinary line on the page; the instrument itself never
| reaches the page. jsdom has no layout, so the layer sits at (0,0) and a client
| point is a page point at zoom 1.
*/

const VIEW: View = { scrollX: 0, scrollY: 0, zoom: 1 };
const CENTRE: Point = [960, 540];

function mount(kind: "ruler" | "protractor" | "compass" | "set-square", view = VIEW) {
  const onDraw = vi.fn();
  const utils = render(
    <OverlayLayer view={view}>
      <GeometryTool kind={kind} view={view} centre={CENTRE} onDraw={onDraw} onClose={vi.fn()} />
    </OverlayLayer>,
  );
  const lane = utils.container.querySelector("[data-lane]") as Element;
  return { onDraw, lane, ...utils };
}

const drawn = (onDraw: ReturnType<typeof vi.fn>) => onDraw.mock.calls[0][0] as Point[];
const near = (a: Point, b: Point) => expect(distance(a, b)).toBeLessThan(0.5);

describe("geometry instruments", () => {
  it("the ruler draws a straight line snapped to its edge", () => {
    // The ruler is 15 cm (600) long, centred: its edge runs y = 540 from x = 660 to 1260.
    const { onDraw, lane } = mount("ruler");
    fireEvent.pointerDown(lane, { clientX: 700, clientY: 520, pointerId: 1 });
    fireEvent.pointerMove(lane, { clientX: 900, clientY: 500, pointerId: 1 });
    fireEvent.pointerUp(lane, { clientX: 900, clientY: 500, pointerId: 1 });

    const [from, to] = drawn(onDraw);
    near(from, [700, 540]);
    near(to, [900, 540]);
  });

  it("follows the board's scroll and zoom: the same edge, wherever the page is on screen", () => {
    const view: View = { scrollX: -100, scrollY: 50, zoom: 2 };
    const { onDraw, lane } = mount("ruler", view);
    // Page (700, 530) sits on screen at ((700 − 100)·2, (530 + 50)·2).
    fireEvent.pointerDown(lane, { clientX: 1200, clientY: 1160, pointerId: 1 });
    fireEvent.pointerMove(lane, { clientX: 1600, clientY: 1160, pointerId: 1 });
    fireEvent.pointerUp(lane, { clientX: 1600, clientY: 1160, pointerId: 1 });

    const [from, to] = drawn(onDraw);
    near(from, [700, 540]);
    near(to, [900, 540]);
  });

  it("the protractor draws the whole angle under the pen: both sides from the vertex, and its mark", () => {
    const { onDraw, lane } = mount("protractor");
    fireEvent.pointerDown(lane, { clientX: 960, clientY: 330, pointerId: 1 });
    fireEvent.pointerUp(lane, { clientX: 960, clientY: 330, pointerId: 1 });

    expect(onDraw).toHaveBeenCalledTimes(2); // the sides, then the mark
    const [base, vertex, side] = drawn(onDraw);
    near(vertex, CENTRE);
    near(base, [960 + 330, 540]); // along the base, past the rim (1.5 × 220)
    near(side, [960, 540 - 330]); // straight up: 90°
  });

  it("the compass draws an arc on its radius", () => {
    const { onDraw, lane } = mount("compass");
    fireEvent.pointerDown(lane, { clientX: 1120, clientY: 540, pointerId: 1 });
    fireEvent.pointerMove(lane, { clientX: 1073, clientY: 653, pointerId: 1 });
    fireEvent.pointerMove(lane, { clientX: 960, clientY: 700, pointerId: 1 });
    fireEvent.pointerUp(lane, { clientX: 960, clientY: 700, pointerId: 1 });

    const points = drawn(onDraw);
    near(points[0], [1120, 540]);
    near(points[points.length - 1], [960, 700]);
    for (const p of points) expect(distance(p, CENTRE)).toBeCloseTo(160);
  });

  it("draws nothing for a tap, and nothing at all unless the pen draws", () => {
    const { onDraw, lane } = mount("set-square");
    fireEvent.pointerDown(lane, { clientX: 900, clientY: 700, pointerId: 1 });
    fireEvent.pointerUp(lane, { clientX: 900, clientY: 700, pointerId: 1 });
    expect(onDraw).not.toHaveBeenCalled();
  });
});
