/**
 * Route-level loading UI. Replaces the removed 950ms artificial splash with a
 * real, streaming-aware skeleton shown only while a segment actually resolves.
 */
export default function Loading() {
  return (
    <div
      className="min-h-[60vh] flex flex-col items-center justify-center gap-4"
      role="status"
      aria-live="polite"
    >
      <span className="sr-only">Loading…</span>
      <div
        className="h-8 w-8 rounded-full border-2 border-slate-200 border-t-[#0055B8] animate-spin motion-reduce:animate-none"
        aria-hidden="true"
      />
      <span className="text-xs font-mono text-slate-400">Loading…</span>
    </div>
  );
}
