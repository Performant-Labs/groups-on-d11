# Responsive & Mobile-First Authoring

> The org-wide default authoring stance for any web UI. Stack-agnostic. Tailwind, plain CSS, and any framework follow the same direction-of-authoring rule.
>
> Sources: WCAG 2.2 (SC 1.4.10 Reflow, SC 1.4.4 Resize Text, SC 2.5.8 Target Size). Stack application: [`../../frameworks/htmx/visual-regression-strategy.md`](../../frameworks/htmx/visual-regression-strategy.md) (the reference structural-overflow matrix), [`../html/data-tables.md`](../html/data-tables.md) (responsive tables).

---

## The rule

**Author mobile-first. The unprefixed/base layer is the smallest supported viewport; larger screens are progressive enhancements layered on with `min-width` conditions.** Never author desktop-down (a desktop base "corrected" for narrow screens with `max-width`).

- **Tailwind:** unprefixed utilities = mobile base; `sm:` / `md:` / `lg:` add enhancements upward. Never reach for a `max-*` variant to shrink a desktop default.
- **Plain CSS:** the resting rule is mobile; `@media (min-width: …)` layers desktop on top. `@media (max-width: …)` is the exception (a targeted narrow-only patch), not the default direction.

```css
/* ✅ mobile-first — base is mobile, min-width enhances upward */
.toolbar { display: grid; grid-template-columns: 1fr; gap: var(--space-2); }
@media (min-width: 768px) { .toolbar { grid-template-columns: repeat(3, 1fr); } }

/* ❌ desktop-first — base is desktop, max-width shrinks down */
.toolbar { display: grid; grid-template-columns: repeat(3, 1fr); }
@media (max-width: 767px) { .toolbar { grid-template-columns: 1fr; } }
```

A common tell of an accidental desktop-first base: an **unprefixed multi-column grid** (`grid-cols-3`, `grid-cols-4`) with no mobile base. That value belongs behind a breakpoint (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-4`).

---

## Support posture — "mobile-degraded-functional"

The default product posture (override per project only with an explicit written commitment):

| Tier | Width | Commitment |
|---|---|---|
| Desktop | ≥ `lg` (~1024px+) | **Primary.** Full fidelity. |
| Tablet | `md` (768–1023) | Supported. |
| Mobile | 360–767 | **Degraded-functional** — every action reachable and usable, no horizontal scrolling of the page, layout may simplify. |
| < 360 | — | Unsupported. |

"Degraded-functional" means: nothing is *unreachable* on a phone (nav, primary actions, data all accessible), even if the layout is denser or reflows. It is **not** a licence to pin the viewport to a desktop width.

---

## Viewport meta — the non-negotiables

Every page ships exactly one viewport meta, and it must allow the device to drive layout and the user to zoom:

```html
<meta name="viewport" content="width=device-width, initial-scale=1">
```

- **Never** pin a fixed width (`width=1280`, `width=1024`, etc.) — that re-pins mobile browsers to a desktop layout and defeats every mobile-first rule below it.
- **Never** add `maximum-scale=1` or `user-scalable=no` — disabling pinch-zoom is a WCAG **1.4.4 Resize Text** failure. `initial-scale=1` only.
- Keep it in **one** place (a single base/head template), not duplicated per layout.

---

## The standard viewport set

When a matrix is needed (testing, visual diffs, audits), use **360 / 768 / 1280**, ordered smallest-first:

- **360** — the mobile floor (< 360 unsupported).
- **768** — the tablet / `md` boundary.
- **1280** — the desktop primary.

Use the same three widths everywhere so testing, audit, and design tooling agree. (Historically docs drifted to `375`/`Pixel 5`; **360** is the org default floor.)

---

## The cheap deterministic gate: no horizontal overflow

The single most valuable responsive check is also the cheapest and most deterministic — it is **Tier 2 (structural), not a screenshot**:

```ts
// At each viewport in {360, 768, 1280}:
await page.setViewportSize({ width, height: 800 });
const overflow = await page.evaluate(
  () => document.documentElement.scrollWidth - window.innerWidth,
);
expect(overflow).toBeLessThanOrEqual(0);   // page itself must not scroll sideways
```

> [!WARNING]
> A wide element inside `overflow-hidden` **clips silently** and does NOT register as document overflow — so `scrollWidth ≤ innerWidth` is necessary but not sufficient. Pair it with a Tier-1 grep that wide-content wrappers (tables, code blocks) are `overflow-x-auto`, **never `overflow-hidden`**. See [`../html/data-tables.md`](../html/data-tables.md).

Run this matrix as a **blocking gate** on any UI change. See [`../../testing/verification-cookbook.md`](../../testing/verification-cookbook.md) §"No horizontal overflow" and [`../../testing/visual-regression-strategy.md`](../../testing/visual-regression-strategy.md).

---

## Touch targets

Primary action rows / interactive controls that a user taps on a phone must meet a minimum hit area:

- **WCAG 2.2 SC 2.5.8 Target Size (Minimum) — AA:** 24×24 CSS px floor.
- **House preferred — 44×44 CSS px** on primary action rows (the Apple/Material touch target). Use 44×44 for buttons, nav items, and table-row actions on mobile; 24×24 is the hard floor, not the goal.

Bake the minimum into component classes (see [`../../frameworks/tailwind/conventions.md`](../../frameworks/tailwind/conventions.md) §Responsive), not ad-hoc per template.

---

## Navigation at narrow widths

Primary navigation must remain reachable below `md`. The standard pattern is an off-canvas drawer toggled by a hamburger button (with `aria-expanded` + a focus trap), not a nav that simply disappears or requires horizontal scroll. A nav control gated *above* `md:` (so it's invisible on mobile) is a defect.

---

## Tables and wide content

Wide tabular data on narrow viewports uses one of two patterns — never `overflow-hidden`:

1. **Scroll container** — wrap in `overflow-x-auto` (the always-safe default).
2. **Column-priority / progressive disclosure** — keep essential columns always visible; reveal lower-priority columns at wider breakpoints with `hidden md:table-cell` (the column **stays in the DOM** for AT, copy-paste, and find-in-page — `display` only toggles). Direction is mobile-first: base = essential columns, `md:`/`lg:` reveal upward.

Full guidance: [`../html/data-tables.md`](../html/data-tables.md).

---

## Reflow (WCAG 2.2 SC 1.4.10 — AA)

Content must be usable at **320 CSS px width / 400% zoom without two-dimensional scrolling** (no horizontal scroll to read a vertical block of content; data tables and the like are the allowed exceptions via their own scroll container). Authoring mobile-first and passing the no-horizontal-overflow gate above is how you satisfy Reflow in practice. See [`../../architecture/aria-authoring.md`](../../architecture/aria-authoring.md) §"Reflow & target size".

---

## Checklist (any UI change)

- [ ] Base layer is mobile; larger screens layered with `min-width` / `sm:`/`md:`/`lg:` — no unprefixed desktop grid.
- [ ] Exactly one `<meta viewport>` = `width=device-width, initial-scale=1`; no fixed width; no `maximum-scale`/`user-scalable=no`.
- [ ] No horizontal page overflow at 360 / 768 / 1280 (structural gate).
- [ ] Wide content in `overflow-x-auto` or column-priority — never `overflow-hidden`.
- [ ] Primary tap targets ≥ 44×44 (24×24 hard floor).
- [ ] Primary nav reachable below `md` (drawer + hamburger, labelled, focus-trapped).
- [ ] Usable at 320px / 400% zoom (Reflow, SC 1.4.10).
