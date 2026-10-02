import { describe, expect, it } from "vitest";

import { fixRtlTextAnchors } from "./arabic-font";

/** The shape Excalidraw 0.18.1's SVG writer produces for one text line. */
function svgWith(lines: { x: string; direction: string; anchor: string }[]): SVGSVGElement {
  const ns = "http://www.w3.org/2000/svg";
  const svg = document.createElementNS(ns, "svg");
  for (const line of lines) {
    const text = document.createElementNS(ns, "text");
    text.setAttribute("x", line.x);
    text.setAttribute("direction", line.direction);
    text.setAttribute("text-anchor", line.anchor);
    svg.appendChild(text);
  }
  return svg;
}

const anchors = (svg: SVGSVGElement) =>
  [...svg.querySelectorAll("text")].map((t) => t.getAttribute("text-anchor"));

describe("fixRtlTextAnchors", () => {
  it("right-aligned RTL moves to start, so the line ends at the box's right edge", () => {
    const svg = svgWith([{ x: "412", direction: "rtl", anchor: "end" }]);
    fixRtlTextAnchors(svg);
    expect(anchors(svg)).toEqual(["start"]);
  });

  it("leaves left-aligned RTL, centred text and LTR text exactly as Excalidraw wrote them", () => {
    const svg = svgWith([
      { x: "0", direction: "rtl", anchor: "end" },
      { x: "206", direction: "rtl", anchor: "middle" },
      { x: "412", direction: "ltr", anchor: "end" },
      { x: "0", direction: "ltr", anchor: "start" },
    ]);
    fixRtlTextAnchors(svg);
    expect(anchors(svg)).toEqual(["end", "middle", "end", "start"]);
  });
});
