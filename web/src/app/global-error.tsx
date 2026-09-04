"use client";

import { useEffect } from "react";

/**
 * Last-resort boundary for errors thrown in the root layout itself, where the
 * normal error.tsx cannot render. It must supply its own <html>/<body>.
 */
export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error("[app] root error:", error?.digest ?? error?.message);
  }, [error]);

  return (
    <html lang="en">
      <body
        style={{
          margin: 0,
          minHeight: "100vh",
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
          justifyContent: "center",
          fontFamily: "system-ui, -apple-system, Segoe UI, sans-serif",
          background: "#F8FAFC",
          color: "#0F172A",
          textAlign: "center",
          padding: "24px",
        }}
      >
        <h1 style={{ fontSize: "24px", fontWeight: 800, margin: "0 0 8px" }}>
          TenderHub is temporarily unavailable
        </h1>
        <p style={{ fontSize: "14px", color: "#475569", maxWidth: "420px", margin: "0 0 24px" }}>
          We hit an unexpected problem. Please try again in a moment.
        </p>
        <button
          type="button"
          onClick={reset}
          style={{
            background: "#0055B8",
            color: "#fff",
            border: 0,
            borderRadius: "12px",
            padding: "12px 24px",
            fontWeight: 800,
            fontSize: "12px",
            textTransform: "uppercase",
            letterSpacing: "0.06em",
            cursor: "pointer",
          }}
        >
          Try again
        </button>
      </body>
    </html>
  );
}
