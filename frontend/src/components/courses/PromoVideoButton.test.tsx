import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { PromoVideoButton } from "./PromoVideoButton";

/*
| الفيديو الترويجي — زرٌّ يكشفُ إطاراً، ولا يُحمَّلُ شيءٌ قبلَ الضغط.
|
| ⚠️ التوكيدُ الأوّلُ هو الذي لا يُستغنى عنه. إطارٌ مُركَّبٌ مع الصفحةِ ومخفيٌّ
| بـ`hidden` يبدو على الشاشةِ كما لو لم يكن هناك شيء، ويُحمِّلُ نصوصَ الطرفِ الثالثِ
| وكوكيزَه كاملةً لكلِّ زائرِ كلِّ صفحةِ كورس — وأكثرُهم لن يضغط. فاختبارٌ يبدأُ من
| «بعدَ الضغط» يمرُّ على التنفيذِ الذي كُتب هذا القرارُ لمنعِه بالضبط.
|
| ⚠️ و`fireEvent` لا `userEvent`: سابقتا `ConfirmButton` و`PasswordField`.
*/

describe("PromoVideoButton", () => {
  it("mounts no iframe at all before the button is pressed", () => {
    const { container } = render(
      <PromoVideoButton videoId="dQw4w9WgXcQ" courseTitle="أساسيّات التفاضل" />,
    );

    // Not «hidden», not «zero height» — ABSENT. A hidden frame has already
    // fetched everything the visitor never asked for.
    expect(container.querySelector("iframe")).toBeNull();
  });

  it("reveals a frame built from the id once pressed", () => {
    const { container } = render(
      <PromoVideoButton videoId="dQw4w9WgXcQ" courseTitle="أساسيّات التفاضل" />,
    );

    fireEvent.click(screen.getByRole("button"));

    const frame = container.querySelector("iframe");
    expect(frame).not.toBeNull();
    expect(frame?.getAttribute("src")).toContain("dQw4w9WgXcQ");
    // The title carries the course, so the frame is not an unnamed box.
    expect(frame?.getAttribute("title")).toContain("أساسيّات التفاضل");
  });

  /*
  | The id reaches `src` only through `encodeURIComponent`, and the host is a
  | constant in the component. This is the second lock on FR-008 — the first is
  | that the server stores an extracted id and never the pasted string.
  */
  it("never lets a pasted string reach the frame's src", () => {
    const { container } = render(
      <PromoVideoButton
        videoId={'" onload="alert(1)'}
        courseTitle="أساسيّات التفاضل"
      />,
    );

    fireEvent.click(screen.getByRole("button"));

    const src = container.querySelector("iframe")?.getAttribute("src") ?? "";
    expect(src.startsWith("https://www.youtube-nocookie.com/embed/")).toBe(true);
    expect(src).not.toContain("onload=");
    expect(src).not.toContain('"');
  });

  it("hides the frame again on a second press", () => {
    const { container } = render(
      <PromoVideoButton videoId="dQw4w9WgXcQ" courseTitle="أساسيّات التفاضل" />,
    );

    fireEvent.click(screen.getByRole("button"));
    expect(container.querySelector("iframe")).not.toBeNull();

    fireEvent.click(screen.getByRole("button"));
    expect(container.querySelector("iframe")).toBeNull();
  });
});
