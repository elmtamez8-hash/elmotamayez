import { describe, expect, it, vi } from "vitest";

const generateSitemaps = vi.fn();

vi.mock("@/app/sitemap", () => ({ generateSitemaps: () => generateSitemaps() }));

import robots from "@/app/robots";
import { SITE_URL } from "@/lib/site";
import { GET } from "./route";

/*
  ⛔ `/sitemap.xml` answered 404 on production (Next serves only the chunks), and
  robots.txt carried a non-standard `Host:` line.
*/
describe("the sitemap index at /sitemap.xml", () => {
  it("names every chunk `generateSitemaps()` returns, as XML", async () => {
    generateSitemaps.mockResolvedValue([{ id: 0 }, { id: 1 }]);

    const response = await GET();
    const body = await response.text();

    expect(response.headers.get("Content-Type")).toContain("application/xml");
    expect(body).toContain("<sitemapindex");
    expect(body).toContain(`<loc>${SITE_URL}/sitemap/0.xml</loc>`);
    expect(body).toContain(`<loc>${SITE_URL}/sitemap/1.xml</loc>`);
  });
});

describe("robots.txt", () => {
  it("points at the index and carries no Host line", () => {
    const rules = robots();

    expect(rules.sitemap).toBe(`${SITE_URL}/sitemap.xml`);
    expect(rules).not.toHaveProperty("host");
  });
});
