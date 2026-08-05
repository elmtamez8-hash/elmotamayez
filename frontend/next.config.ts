import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // The Next.js dev badge defaults to bottom-left. On an RTL page that is the
  // inline-end corner, which is exactly where the WhatsApp and back-to-top
  // buttons sit — so it overlapped them and read as part of the product.
  // Dev-only either way; compile and runtime errors still surface.
  devIndicators: false,

  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: "http://localhost:8000/api/:path*",
      },
      {
        // Uploaded media (payment receipts) is served from the backend's public
        // disk as a relative /storage URL; behind nginx in production both live
        // on one host, so only dev needs the proxy.
        source: "/storage/:path*",
        destination: "http://localhost:8000/storage/:path*",
      },
    ];
  },
};

export default nextConfig;
