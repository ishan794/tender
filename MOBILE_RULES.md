# TenderHub — Mobile / Responsive UI Rules

These rules govern all UI, layout, component, and styling decisions across TenderHub. **One responsive codebase, every section reflows.**

---

## Rule 0 — One codebase, not two
Never build a separate mobile route tree, a separate mobile component set, or a `/m/` prefix. Every page is one component that reflows across breakpoints (Tailwind `sm/md/lg/xl`). The only exception: a component whose *interaction pattern* genuinely differs by breakpoint (see Rule 3 — DataTable) may render a different internal shape at small sizes, but it's still one component with one set of props/data, not a parallel duplicate page someone has to remember to update twice.

## Rule 1 — The desktop layout is not the mobile default, shrunk
Each section gets reasoned about at 375px width on its own terms — what should actually be visible, in what order, at what size — not "take the desktop grid and let it wrap." If a 3-column desktop layout becomes 3 tiny unreadable columns at 375px instead of 1 column, that section wasn't made responsive, it was just observed to not crash.

## Rule 2 — Navigation
- Header collapses to a hamburger/menu below the point where nav items stop fitting — every nav item still reachable, none silently dropped.
- Portal shells (bidder `/app`, company `/workspace`, staff `/console`) use a drawer or bottom-tab bar on mobile, never a squeezed sidebar with tiny icons and no labels.
- Sticky headers must not eat more than ~15% of a 375px-tall viewport — check on an actual short viewport (iPhone SE: 667px tall), not just width.

## Rule 3 — Data-heavy components (DataTable especially)
The design system's `DataTable` (sorting, multi-select filters, chips, bulk actions, windowed pagination) is the single hardest thing to get right and the thing most likely to just be left shrunk. It needs an explicit mobile presentation — pick one, per table, deliberately:
- **Card-list transform**: each row becomes a stacked card (label: value pairs), OR
- **Horizontal-scroll table with a frozen/sticky identifying column** (usually the first — reference/title), so the reader always knows which row they're scrolling.

A table that's just visually smaller with 8 columns crushed into 375px is not a mobile treatment — it's unreadable and it's the single most common way "responsive" work gets faked. Check every `DataTable` usage individually: PaymentsQueue, OrganisationsTable, SourcesTable, SuppliersTable, TeamTable, AuctionsTable, PipelineTable, VaultTable — each needs its own decision made, not a global CSS shrink.

## Rule 4 — Filters and facets
Desktop sidebar facets (category, district, value band, sector, status) become a **drawer or bottom sheet** on mobile, opened by a visible "Filters" button that shows an active-filter count. Never a sidebar squeezed to 60px of collapsed icons with no way to actually use it.

## Rule 5 — Touch targets
Minimum 44×44px tap target for every button, menu item, table row action, and icon-only control. No affordance that only appears on `:hover` — if desktop reveals something on hover (a row action, a tooltip, a secondary label), mobile needs a tap-to-reveal or always-visible equivalent. Hover-only content is invisible on a touchscreen, full stop.

## Rule 6 — Modals and overlays
On mobile, modals become full-screen sheets or bottom sheets — never a small centered dialog with tiny padding that's hard to dismiss or scroll inside. `ConfirmDialog`, the workspace modals, ratings modal, all need this.

## Rule 7 — Forms
Single column, larger inputs, and correct input types/`inputmode` so the right mobile keyboard shows up: `type="tel"` for phone fields, `type="email"` for email, numeric `inputmode` for value/amount fields. A form that's usable with a mouse and unusable with a thumb is not done.

## Rule 8 — Viewport height
Use `dvh`, never bare `100vh`, for any full-height section. `100vh` is measured including the mobile browser's address bar and breaks (content cut off or an extra scroll gap) on iOS Safari in particular. This is a one-line rule with a very common, very visible bug behind it.

## Rule 9 — No hardcoded pixel widths without a responsive counterpart
Any `width: 960px` or Tailwind arbitrary value like `w-[960px]` that isn't wrapped in a breakpoint variant (`md:w-[960px]`) is a page that will overflow horizontally on mobile. Grep for these explicitly rather than trusting visual spot-checks.

## Rule 10 — Density setting is not a substitute for Rule 3
The design system's comfortable/compact density toggle changes row height and padding on desktop tables. It does not make a table mobile-usable by itself — compact mode with 8 columns at 375px is still 8 columns at 375px. Rule 3's card/scroll decision still has to happen.

## Rule 11 — Footer
Stacks to a single column or organized 2-column mobile grid without wrapping words awkwardly. No orphaned floated columns, no columns that end up narrower than their content and wrap every word.

## Rule 12 — Safe area insets
Any fixed/sticky header, footer, or bottom nav must respect `env(safe-area-inset-top)` / `env(safe-area-inset-bottom)` so it doesn't sit under a notch or a home-indicator bar on modern phones.

## Rule 13 — No horizontal scroll of the page itself
Only specific, intentional elements (a table per Rule 3, an image carousel) may scroll horizontally. The page body as a whole must never require horizontal scrolling to read content — that's always a bug, not a design choice, and it's the fastest tell that a section was shipped unchanged from desktop.

## Rule 14 — Verification is a screenshot or a DevTools check, not a description
Same principle as the backend rules: "this is responsive now" is not evidence. A claim that a section is mobile-ready must be backed by an actual resize-and-look check at 375px, described concretely (what's visible, what happened to the table/nav/filters) — not asserted from reading the Tailwind classes and assuming they'll behave.
