"use client";

import { useEffect } from "react";

/**
 * Route-segment error boundary. Catches render/data errors below the root
 * layout and shows a branded recovery screen instead of Next.js's default.
 * The error is logged (and, in production, is where a Sentry capture belongs)
 * but its message is never shown to the user — it can contain internals.
 */
export default function Error({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error("[app] unhandled error:", error?.digest ?? error?.message);
  }, [error]);

  return (
    <div className="min-h-screen flex flex-col items-center justify-center px-6 text-center bg-[#F8FAFC]">
      <span className="font-display font-black text-2xl tracking-tight text-[#0F172A] mb-6">
        TENDER<span className="text-[#0055B8]">HUB</span>
      </span>
      <div className="bg-white border border-slate-200/90 rounded-2xl shadow-md p-8 lg:p-10 max-w-[440px] w-full">
        <h1 className="font-display text-2xl sm:text-3xl font-black uppercase tracking-tight text-[#0F172A] mb-2">
          Something went wrong
        </h1>
        <p className="text-sm text-slate-600 leading-relaxed mb-7">
          We hit an unexpected problem loading this page. This has been logged. You can
          try again, or head back and continue.
        </p>
        <div className="flex flex-col gap-3">
          <button
            type="button"
            onClick={reset}
            className="w-full bg-[#0055B8] hover:bg-[#004394] text-white font-extrabold text-xs py-3 rounded-xl transition-all uppercase tracking-wider cursor-pointer"
          >
            Try again
          </button>
          <a
            href="/"
            className="text-xs font-bold text-slate-500 hover:text-[#0055B8] transition-colors"
          >
            Return home
          </a>
        </div>
        {error?.digest ? (
          <p className="mt-6 text-[10px] font-mono text-slate-400">Reference: {error.digest}</p>
        ) : null}
      </div>
    </div>
  );
}
