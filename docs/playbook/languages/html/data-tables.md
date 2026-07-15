# Data Tables — Semantic HTML and Responsive Patterns

Org-wide standards for marking up and styling **tabular data**: the table-vs-`div` decision, semantic table structure, the responsive-layout patterns that keep wide tables usable on narrow viewports, and the accessibility traps to avoid.

Standard: **WCAG 2.2 AA**. See `architecture/aria-authoring.md` for general ARIA and the "semantic HTML first" principle this doc applies to tables; see `languages/css/css-change-workflow.md` for which cascade layer responsive table styles belong in.

---

## The one rule that resolves "tables or divs?"

> [!IMPORTANT]
> **Layout tables are dead. Data tables are not.** Use a semantic `<table>` for *tabular data* (rows × columns of related values). Never use `<table>` for *page layout* — that's CSS Grid / Flexbox. The folklore that "modern sites use divs instead of tables" conflates the two: divs replaced tables for **layout**, never for **data**.

Rendering genuinely tabular data with `<div>`s is the anti-pattern — not the modern practice.

## Core principles

1. **Semantic `<table>` first.** `<table>`, `<thead>`, `<tbody>`, `<tr>`, `<th scope>`, `<td>`, `<caption>` give you row/column semantics, header→cell associations, screen-reader "table browse" mode, find-in-page, and copy-paste-into-a-spreadsheet — for free.
2. **`<div>` + ARIA grid roles is an escape hatch, not a default.** It exists only for behavior native tables can't do (see [When a div grid is justified](#when-a-div-grid-is-actually-justified)). It costs you the entire `role="table/row/columnheader/cell"` + `aria-rowindex` apparatus, hand-maintained and easy to ship broken.
3. **Layout is a CSS problem, not a markup problem.** A table that overflows narrow screens is fixed with CSS (scroll container, column priority) — not by abandoning `<table>`.
4. **Never break table semantics with CSS `display`.** Changing a table element's `display` away from its native `table*` value strips its implicit ARIA role in several browser/AT combinations (the card-transform trap, below).

## Anatomy of a correct data table

```html
<table>
  <caption class="sr-only">Test runs, most recent first</caption>
  <thead>
    <tr>
      <th scope="col">Run</th>
      <th scope="col">Status</th>
      <th scope="col">Duration</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <th scope="row">PR-142</th>   <!-- row header, not a <td> -->
      <td>Failed</td>
      <td>3m 12s</td>
    </tr>
  </tbody>
</table>
```

- **`<th scope="col">` / `<th scope="row">`** — associates headers with cells so AT announces "Status, Failed", not a bare "Failed".
- **`<caption>`** — the table's accessible name; `sr-only` if you don't want it shown.
- One `<thead>`, one `<tbody>` (plus `<tfoot>` for totals). Don't nest tables.

## Responsive patterns — keep the semantics, change the CSS

Default table layout (`display: table`) refuses to shrink; that is the source of mobile overflow and clipping. Three sanctioned patterns, in preference order:

### 1. Horizontal-scroll container — default, safest
Wrap the table in an `overflow-x-auto` element with a `min-width` on the table. Preserves every cell and all semantics; the user scrolls the table, not the page.

```html
<div class="overflow-x-auto">
  <table class="min-w-[40rem] w-full"> … </table>
</div>
```

> [!WARNING]
> Use `overflow-x-auto`, **not** `overflow-hidden`. `overflow-hidden` silently *clips* the off-screen columns with no scrollbar — the data is gone, invisibly. This is a common and nasty bug; it reads as "the table fits" when it doesn't.

### 2. Column-priority / progressive disclosure — best UX for wide tables
Show the essential columns always; reveal lower-priority ones at wider breakpoints. **Keep hidden columns in the DOM** (`hidden md:table-cell`, not removed) so AT, copy-paste, and find-in-page still see every column.

```html
<th scope="col">Name</th>                                <!-- always -->
<th scope="col" class="hidden sm:table-cell">Duration</th>
<th scope="col" class="hidden lg:table-cell">Reporter</th>
<!-- the matching <td>s carry the SAME hidden sm:/lg:table-cell classes -->
```

Choose a deliberate priority order (identifier + status first; metadata last). This is the recommended pattern for dense tables on small screens.

### 3. Stacked "cards" — last resort, with a sharp caveat
Transforming each row into a card (`display:block` + `data-label` `::before` labels) reads well on a phone but **loses cross-row scanning** (you can't compare row 3's status against row 8's) and is an accessibility minefield.

> [!CAUTION]
> Setting `display: block / grid / flex` on `<table>`, `<tr>`, `<td>`, or `<th>` **removes their implicit ARIA table semantics** in multiple browsers — the screen reader stops announcing it as a table. If you must card-transform, re-add `role="table"`, `role="row"`, `role="cell"`, `role="columnheader"` explicitly to restore what the CSS broke. Reference: Adrian Roselli, *"Tables, CSS Display Properties, and ARIA."* Prefer patterns 1–2, which never touch `display`.

## When a `<div>` grid is actually justified

Reach for a `role="grid"` div-based grid **only** for behavior the native element cannot provide:

- **Virtualized rendering** of very large datasets (tens of thousands of rows) where only the visible rows are mounted.
- **Full data-grid interaction** — cell-level keyboard focus (roving `tabindex`), inline editing, column resize/reorder, frozen panes.

Even then, prefer a library that renders a real `<table>` (e.g. TanStack Table) over hand-rolled div soup, and budget for the complete ARIA `grid` pattern (W3C APG "Grid") if you go div-based. For read-mostly tables — the overwhelming majority — a semantic `<table>` with patterns 1–2 is correct.

## CSS toolkit for tables

| Need | Tool |
|---|---|
| Stop columns from ballooning | `table-layout: fixed` + explicit `<col>` / cell widths |
| Header stays while body scrolls | `position: sticky; top: 0` on `<thead>` cells |
| Long cell text | `text-overflow: ellipsis` + `max-width` (with a `title`/tooltip for the full value) |
| Column sizing without per-cell classes | `<colgroup><col>` |
| Wide table inside a card/panel | scroll container (pattern 1) — never `overflow-hidden` |

## Quick decision guide

- Rows and columns of related data? → **semantic `<table>`.**
- Page or section layout? → **CSS Grid / Flexbox**, never a table.
- Table too wide for mobile? → **`overflow-x-auto` scroll**, then **column-priority** for better UX. Never `overflow-hidden`.
- Need virtualization or spreadsheet-grade cell interaction? → a **`role="grid"`** component (ideally one that still emits a real `<table>`), accepting the ARIA cost.
- Tempted to card-ify rows? → reconsider; if you must, restore the ARIA roles the `display` change strips.

## See also

- `architecture/aria-authoring.md` — landmark roles, labelling, focus, and the semantic-HTML-first principle.
- `languages/css/css-change-workflow.md` — responsive table styles are component-level (L4); the responsive `@media`/breakpoint condition attaches to the layer of the rule it modifies, not a new "mobile" layer.
- `frameworks/tailwind/` — the `hidden md:table-cell` / `overflow-x-auto` / `min-w-*` utilities used above.
- `testing/verification-cookbook.md` — contrast ratios and automated a11y checks for rendered table output.
