import { describe, expect, it } from "vitest";

import { videoEmbedUrl } from "./video-embed";

/*
| الحدُّ الأمنيُّ للفيديو التعريفيّ: العنوانُ الذي يخرجُ من هنا يُغرَسُ في `<iframe>`
| على صفحةٍ عامّة. فالحالاتُ الرافضةُ هي المقصودةُ بهذا الملفّ، والقابلةُ تُثبِتُ
| أنّ الرفضَ ليسَ رفضاً للكلِّ.
*/
describe("videoEmbedUrl", () => {
  it("builds a nocookie embed from every YouTube spelling", () => {
    const embed = "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ";

    expect(videoEmbedUrl("https://www.youtube.com/watch?v=dQw4w9WgXcQ")).toBe(embed);
    expect(videoEmbedUrl("https://youtu.be/dQw4w9WgXcQ")).toBe(embed);
    expect(videoEmbedUrl("https://youtube.com/embed/dQw4w9WgXcQ")).toBe(embed);
    expect(videoEmbedUrl("https://www.youtube.com/shorts/dQw4w9WgXcQ")).toBe(embed);
  });

  it("builds a player url from both Vimeo spellings", () => {
    expect(videoEmbedUrl("https://vimeo.com/347119375")).toBe(
      "https://player.vimeo.com/video/347119375",
    );
    expect(videoEmbedUrl("https://player.vimeo.com/video/347119375")).toBe(
      "https://player.vimeo.com/video/347119375",
    );
  });

  it("refuses anything that is not one of those two hosts", () => {
    expect(videoEmbedUrl("https://evil.example.com/embed/dQw4w9WgXcQ")).toBeNull();
    // ⚠️ اسمُ المضيفِ يُقارَنُ كاملاً: بادئةٌ تحملُ الاسمَ ليست المضيف.
    expect(videoEmbedUrl("https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ")).toBeNull();
  });

  it("refuses a non-https scheme, script included", () => {
    expect(videoEmbedUrl("javascript:alert(1)")).toBeNull();
    expect(videoEmbedUrl("data:text/html,<script>alert(1)</script>")).toBeNull();
    expect(videoEmbedUrl("http://www.youtube.com/watch?v=dQw4w9WgXcQ")).toBeNull();
  });

  it("refuses a malformed url, a bare id and an empty value", () => {
    expect(videoEmbedUrl("dQw4w9WgXcQ")).toBeNull();
    expect(videoEmbedUrl("not a url at all")).toBeNull();
    expect(videoEmbedUrl("")).toBeNull();
    expect(videoEmbedUrl(null)).toBeNull();
  });

  it("refuses an id outside the closed character set or the wrong length", () => {
    expect(videoEmbedUrl("https://www.youtube.com/watch?v=short")).toBeNull();
    expect(videoEmbedUrl("https://www.youtube.com/watch?v=../../../etc")).toBeNull();
    expect(videoEmbedUrl("https://vimeo.com/abc")).toBeNull();
  });
});
