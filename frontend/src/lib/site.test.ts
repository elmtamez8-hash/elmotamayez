import { describe, expect, it, vi } from "vitest";

import { resolveSiteUrl } from "./site";

/*
  ⛔ Prerendered pages (/terms, /refunds, the 404) carried
  `http://localhost:3000` in their canonical and og:image on the first request
  after every deploy: `SITE_URL` existed only at run time, and the build baked
  the fallback in.
*/
describe("resolveSiteUrl", () => {
  it("prefers SITE_URL and drops a trailing slash", () => {
    expect(resolveSiteUrl({ SITE_URL: "https://elmotamayez.tech/", NEXT_PUBLIC_APP_URL: "https://other.test" })).toBe(
      "https://elmotamayez.tech",
    );
  });

  it("falls back to NEXT_PUBLIC_APP_URL — an empty SITE_URL is unset, as compose writes it", () => {
    expect(resolveSiteUrl({ SITE_URL: "", NEXT_PUBLIC_APP_URL: "https://elmotamayez.tech" })).toBe(
      "https://elmotamayez.tech",
    );
  });

  it("reaches localhost only with neither set, and says so in a production build", () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => undefined);

    expect(resolveSiteUrl({ NODE_ENV: "development" })).toBe("http://localhost:3000");
    expect(warn).not.toHaveBeenCalled();

    expect(resolveSiteUrl({ NODE_ENV: "production" })).toBe("http://localhost:3000");
    expect(warn).toHaveBeenCalledOnce();

    warn.mockRestore();
  });
});
