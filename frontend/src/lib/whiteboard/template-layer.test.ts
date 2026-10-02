import { describe, expect, it } from "vitest";

import { migrateTemplatePictures, templateOnFrame as frameTemplate } from "@/lib/whiteboard/page-model";

const frame = { id: "frame:p", type: "frame", x: 0, y: 0, width: 1920, height: 2160, customData: { kind: "frame", v: 1 } };
const picture = (y: number) => ({
  id: `t${y}`, type: "image", x: 0, y, width: 1920, height: 1080, fileId: "template:lined:v1", status: "saved",
  frameId: "frame:p", locked: true, customData: { kind: "template", v: 1, name: "lined" },
});

describe("the template is a name on the page's frame", () => {
  it("converts a page stored with template pictures: the pictures go, the name moves to the frame", () => {
    const restored = migrateTemplatePictures([picture(0), picture(1080), frame]);
    expect(restored.some((e) => (e.customData as { kind?: string } | undefined)?.kind === "template")).toBe(false);
    expect(frameTemplate(restored)).toBe("lined");
  });

  it("leaves a page without a template alone", () => {
    const restored = migrateTemplatePictures([frame]);
    expect(frameTemplate(restored)).toBeNull();
    expect(restored).toHaveLength(1);
  });
});
