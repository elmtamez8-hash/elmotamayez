import { describe, expect, it } from "vitest";

import {
  arcPoints,
  centimetres,
  CM,
  distance,
  lineSkeleton,
  projectOntoSegment,
  protractorAngle,
  protractorAngleStrokes,
  protractorRim,
  snapToEdge,
  sweep,
  type Instrument,
  type Point,
} from "@/lib/whiteboard/geometry";

const close = (a: Point, b: Point) => expect(distance(a, b)).toBeLessThan(1e-6);

describe("drawing along an edge", () => {
  it("snaps to the ruler's edge, wherever the ruler has been turned", () => {
    const ruler: Instrument = { kind: "ruler", x: 100, y: 100, rotation: 0, size: 600 };
    close(snapToEdge(ruler, [250, 130])!.point, [250, 100]);
    close(snapToEdge(ruler, [900, 90])!.point, [700, 100]); // past the end: held at the end

    const turned = { ...ruler, rotation: 90 };
    close(snapToEdge(turned, [130, 300])!.point, [100, 300]);
  });

  it("uses the nearest of the set square's three edges", () => {
    const square: Instrument = { kind: "set-square", x: 0, y: 0, rotation: 0, size: 400 };
    close(snapToEdge(square, [200, 10])!.point, [200, 0]); // the base
    close(snapToEdge(square, [-10, -200])!.point, [0, -200]); // the upright leg
    const onHypotenuse = snapToEdge(square, [220, -220])!.point;
    expect(onHypotenuse[0] - onHypotenuse[1]).toBeCloseTo(400); // x − y = 400 on the hypotenuse
  });

  it("projects onto a segment, clamped to its ends", () => {
    close(projectOntoSegment([5, 5], [0, 0], [10, 0]), [5, 0]);
    close(projectOntoSegment([-5, 5], [0, 0], [10, 0]), [0, 0]);
  });
});

describe("the protractor", () => {
  const protractor: Instrument = { kind: "protractor", x: 500, y: 500, rotation: 0, size: 200 };

  it("reads the printed angle, 0 on the right rising to 180 on the left", () => {
    expect(protractorAngle(protractor, [700, 500])).toBe(0);
    expect(protractorAngle(protractor, [500, 300])).toBe(90);
    expect(protractorAngle(protractor, [500 + 100, 500 - 100])).toBe(45);
    expect(protractorAngle(protractor, [300, 500])).toBe(180);
    expect(protractorAngle(protractor, [500, 700])).toBe(0); // below the base: held at 0
  });

  it("puts the ray's end on the rim at that angle, also when the protractor is turned", () => {
    close(protractorRim(protractor, 90), [500, 300]);
    const turned = { ...protractor, rotation: 90 };
    close(protractorRim(turned, 0), [500, 700]);
    expect(protractorAngle(turned, protractorRim(turned, 30))).toBe(30);
  });
});

describe("the compass", () => {
  it("follows a sweep the short way round, so it can pass 180°", () => {
    expect(sweep(170, -170)).toBe(20);
    expect(sweep(-170, 170)).toBe(-20);
  });

  it("draws an arc on its radius, never past a full turn", () => {
    const points = arcPoints([0, 0], 100, 0, 90);
    close(points[0], [100, 0]);
    close(points[points.length - 1], [0, 100]);
    for (const p of points) expect(distance(p, [0, 0])).toBeCloseTo(100);

    const full = arcPoints([0, 0], 100, 0, 999);
    close(full[0], full[full.length - 1]);
  });
});

describe("what is saved", () => {
  it("stores a line from its first point, so it reopens where it was drawn (SC-011)", () => {
    const line = lineSkeleton([
      [300, 400],
      [700, 400],
    ]);
    expect(line).toEqual({ x: 300, y: 400, points: [[0, 0], [400, 0]] });
  });

  it("measures in centimetres on the instruments' scale", () => {
    expect(centimetres(5 * CM)).toBe(5);
    expect(centimetres(123)).toBe(3.1);
  });
});

describe("an angle drawn with the protractor", () => {
  it("draws both sides from the vertex past the rim, and a mark between them", () => {
    const protractor: Instrument = { kind: "protractor", x: 500, y: 500, rotation: 0, size: 200 };
    const [sides, mark] = protractorAngleStrokes(protractor, 90);

    close(sides[1], [500, 500]); // the vertex
    close(sides[0], [800, 500]); // along the base, 1.5 × the radius
    close(sides[2], [500, 200]); // straight up
    close(mark[0], [546, 500]);
    close(mark[mark.length - 1], [500, 454]); // the mark ends on the upright side
  });

  it("turns with the protractor", () => {
    const turned: Instrument = { kind: "protractor", x: 0, y: 0, rotation: 90, size: 100 };
    const [sides] = protractorAngleStrokes(turned, 90);
    close(sides[0], [0, 150]);
    close(sides[2], [150, 0]);
  });
});
