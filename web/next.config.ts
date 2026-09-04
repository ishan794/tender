import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Standalone output: the runner image copies only .next/standalone +
  // .next/static + public instead of the whole node_modules tree, cutting the
  // production image by hundreds of MB and making deploys/rollbacks faster.
  output: "standalone",

  // 1. Load Balancing & Performance Optimization
  compress: true, // Enable gzip & brotli compression across all routes
  poweredByHeader: false, // Remove X-Powered-By header to minimize payload & prevent fingerprinting

  // 2. High-Performance Image Optimization with Edge Caching
  images: {
    remotePatterns: [
      {
        protocol: "https",
        hostname: "images.unsplash.com",
      },
    ],
    minimumCacheTTL: 86400, // 24-hour edge cache for remote imagery
    formats: ["image/avif", "image/webp"],
  },

  // 3. Allow LAN IP origins in development for mobile access. Driven by an env
  //    var (comma-separated) so a developer's home IP is not baked into the
  //    repository; localhost is always allowed.
  allowedDevOrigins: [
    "localhost:3000",
    "127.0.0.1:3000",
    ...(process.env.DEV_LAN_ORIGINS?.split(",").map((s) => s.trim()).filter(Boolean) ?? []),
  ],

  // 4. React Strict Mode for predictable rendering
  reactStrictMode: true,
};

export default nextConfig;
