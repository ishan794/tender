"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState, type ReactNode } from "react";

export function Tabs({ tabs, value, onChange }: { tabs: { key: string; label: string; n?: number }[]; value: string; onChange: (k: string) => void }) {
  return (
    <div role="tablist" className="flex flex-nowrap gap-1 overflow-x-auto no-scrollbar border-b border-ink-200 px-[var(--card-p)] py-0.5">
      {tabs.map((t) => (
        <button key={t.key} role="tab" aria-selected={value === t.key} onClick={() => onChange(t.key)}
          className={`shrink-0 -mb-px whitespace-nowrap border-b-2 px-3 py-2.5 min-h-[44px] inline-flex items-center text-[13px] font-medium transition-all ${value === t.key ? "border-brand-600 text-brand-700 font-bold" : "border-transparent text-ink-500 hover:text-ink-800"}`}>
          {t.label}
          {t.n !== undefined ? <span className="ml-1.5 rounded-full bg-ink-100 px-1.5 py-0.5 font-mono text-[11px] text-ink-500">{t.n}</span> : null}
        </button>
      ))}
    </div>
  );
}

export function NavLink({ href, children }: { href: any; children: ReactNode }) {
  const path = usePathname();
  const active = path === href || (href !== "/" && path.startsWith(href));
  return (
    <Link href={href} className={`rounded-[8px] px-3 py-2 min-h-[44px] md:min-h-0 inline-flex items-center text-[13px] font-medium transition-colors ${active ? "bg-brand-50 text-brand-700" : "text-ink-600 hover:bg-ink-100 hover:text-ink-900"}`}>
      {children}
    </Link>
  );
}

export function Stepper({ steps, current }: { steps: string[]; current: number }) {
  return (
    <ol className="flex flex-wrap items-center gap-x-1 gap-y-2">
      {steps.map((s, i) => {
        const done = i < current, now = i === current;
        return (
          <li key={s} className="flex items-center gap-1">
            <span className={`inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 font-mono text-[11px] font-semibold ${done ? "bg-ok-600 text-white" : now ? "bg-brand-600 text-white" : "bg-ink-200 text-ink-500"}`}>
              {done ? "✓" : i}
            </span>
            <span className={`text-[12px] ${now ? "font-semibold text-ink-900" : done ? "text-ink-600" : "text-ink-400"}`}>{s}</span>
            {i < steps.length - 1 ? <span className="mx-1 text-ink-300">→</span> : null}
          </li>
        );
      })}
    </ol>
  );
}

export function DensityToggle() {
  const [compact, setCompact] = useState(false);
  return (
    <button
      onClick={() => {
        const next = !compact;
        setCompact(next);
        document.documentElement.setAttribute("data-density", next ? "compact" : "comfortable");
      }}
      className="rounded-[8px] border border-ink-300 px-2.5 py-1 text-[12px] text-ink-600 hover:bg-ink-50"
      title="Row density"
    >
      {compact ? "Compact" : "Comfortable"}
    </button>
  );
}
