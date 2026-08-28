import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /*
    ⚠️ REQUIRED BY `docker/frontend.Dockerfile`, WHICH HAS ALWAYS COPIED
    `.next/standalone` — a directory Next does not emit without this line. The
    image build failed at the COPY with no hint that a config key was the cause,
    so the whole frontend was undeployable and nothing said so until somebody
    tried. Standalone is also what makes the runner image small: it traces the
    modules actually imported instead of shipping `node_modules`.
  */
  output: "standalone",

  // The Next.js dev badge defaults to bottom-left. On an RTL page that is the
  // inline-end corner, which is exactly where the WhatsApp and back-to-top
  // buttons sit — so it overlapped them and read as part of the product.
  // Dev-only either way; compile and runtime errors still surface.
  devIndicators: false,

  /*
    The video page that used to live at its own route.

    Its removal is deliberate — a screen that did not know an item's type offered
    to upload a video for an article — but a teacher's open tab, bookmark or back
    button still points at it, and the answer they got was Next's 404. The uuids
    in the old path are exactly what the authoring surface needs to open the same
    item, so the address can be translated rather than mourned.

    Not permanent: a 308 is cached by the browser and by every proxy in front of
    it, and this route may be wanted again one day for something else.
  */
  async redirects() {
    return [
      {
        source: "/manage/courses/:course/lessons/:lesson",
        destination: "/manage/courses/:course/content?lesson=:lesson",
        permanent: false,
      },
    ];
  },

  /*
    ⚠️ THE TARGET IS AN ENV VAR WITH THE DEV VALUE AS ITS DEFAULT, and the reason
    is server-side rendering. In production nginx answers `/api` before the
    request ever reaches Next, so the browser never uses these — but Next itself
    does: `signup/teacher` and `signup/parent/children` are prerendered by
    FETCHING the API, from inside the container, where `localhost:8000` is the
    frontend's own port and nothing is listening. The build then fails with
    `TypeError: fetch failed … ECONNREFUSED` and names no cause.
  */
  async rewrites() {
    const origin = process.env.API_ORIGIN ?? "http://localhost:8000";

    return [
      {
        source: "/api/:path*",
        destination: `${origin}/api/:path*`,
      },
      {
        // Uploaded media (payment receipts) is served from the backend's public
        // disk as a relative /storage URL; behind nginx in production both live
        // on one host, so only dev needs the proxy.
        source: "/storage/:path*",
        destination: `${origin}/storage/:path*`,
      },
    ];
  },
};

export default nextConfig;
