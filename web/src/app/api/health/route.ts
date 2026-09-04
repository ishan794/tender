import { NextResponse } from "next/server";

/**
 * Liveness probe for the web container and the nginx upstream check.
 *
 * Deliberately does NOT touch the API or any database — it answers "is this
 * Next.js process serving requests", nothing more. A readiness probe that also
 * pings the backend would take the web container down whenever the API blips,
 * which is the opposite of what a load balancer should do.
 */
export const dynamic = "force-dynamic";

export function GET() {
  return NextResponse.json(
    { status: "ok", service: "web", now: new Date().toISOString() },
    { headers: { "Cache-Control": "no-store" } },
  );
}
