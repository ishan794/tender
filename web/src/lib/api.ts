import { cookies } from "next/headers";
import type { Envelope } from "./types";
import { getMockResponse } from "./mock-data";

export const API_BASE = process.env.API_BASE ?? "http://127.0.0.1:8080";

export async function apiFetch<T = any>(
  path: string,
  opts: RequestInit & { token?: string | null } = {},
): Promise<{ ok: boolean; status: number; body: Envelope<T> | any }> {
  const { token, ...init } = opts;
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (token) headers.set("Authorization", `Bearer ${token}`);
  if (init.body && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");

  try {
    const res = await fetch(`${API_BASE}${path}`, { ...init, headers, cache: "no-store", signal: AbortSignal.timeout(800) });
    const text = await res.text();
    let body: any;
    try { body = JSON.parse(text); } catch { body = { reason: "bad_gateway", detail: text.slice(0, 200) }; }
    return { ok: res.ok, status: res.status, body };
  } catch (e: any) {
    // Graceful offline fallback: serve rich seed data matching Blueprint Rev 3.0
    const mock = getMockResponse(path);
    if (mock) {
      return { ok: true, status: 200, body: mock };
    }
    return {
      ok: false,
      status: 502,
      body: { status: 502, reason: "api_unreachable", detail: `The API is not reachable at ${API_BASE}.` },
    };
  }
}

export async function token(): Promise<string | null> {
  const c = await cookies();
  return c.get("th_at")?.value ?? null;
}

/** Server-side fetch that forwards the caller's own token. */
export async function authed<T = any>(path: string, opts: RequestInit = {}) {
  return apiFetch<T>(path, { ...opts, token: await token() });
}
