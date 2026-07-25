import type { NextConfig } from "next";

const nextConfig: NextConfig = {
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
