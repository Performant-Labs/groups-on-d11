# ARIA Authoring Guide

Org-wide standards for writing accessible HTML in server-rendered and HTMX-driven applications. Covers landmark roles, interactive widget patterns, live regions, labelling, focus management, keyboard navigation, and HTMX-specific considerations.

Standard: **WCAG 2.2 AA** (see `testing/verification-cookbook.md` for contrast ratios and automated testing setup).

---

## Core principles

1. **Use semantic HTML first.** A `<button>` needs no ARIA; a `<div>` acting as a button needs `role="button"`, `tabindex="0"`, and keyboard event handlers. The correct element is always less work than ARIA retrofitting.
2. **ARIA supplements, never replaces.** Adding `role="checkbox"` to a `<div>` gives it an accessible name in the tree but not keyboard behaviour — you must also wire `keydown` handlers. Semantic HTML gives both for free.
3. **No ARIA is better than wrong ARIA.** An incorrect `role` actively breaks screen reader behaviour. If unsure, omit the attribute and use a semantic element.
4. **Every interactive element must be keyboard-operable.** If you can click it, you must be able to reach it with Tab and activate it with Enter or Space.
5. **Dynamic content must be announced.** Any region updated by HTMX, Alpine, or JS without a full navigation must use a live region so screen readers learn about the change.

---

## Landmark roles

Landmarks let screen reader users jump directly to major page regions. Use HTML5 sectioning elements — they carry implicit landmark roles and require no extra attributes.

| HTML element | Implicit role | aria-label required? | Notes |
|---|---|---|---|
| `<header>` (top-level) | `banner` | No | One per page; top-level site header |
| `<nav>` | `navigation` | **Yes** — when multiple navs exist | "Main navigation", "Breadcrumb", "Pagination" |
| `<main>` | `main` | No | One per page; skip link target |
| `<aside>` | `complementary` | Yes — when multiple asides exist | Sidebar, related content |
| `<footer>` (top-level) | `contentinfo` | No | One per page |
| `<section>` | `region` (only with an accessible name) | Yes, or use `aria-labelledby` | Without a name, `<section>` has no landmark role |
| `<form>` | `form` (only with an accessible name) | Yes, or use `aria-labelledby` | Without a name, `<form>` has no landmark role |
| `<search>` | `search` | No | HTML5.2 element for search forms |

### Multiple navs — always label them

```html
<!-- ✅ Two navs on the same page — both labelled -->
<nav aria-label="Main navigation">...</nav>
<nav aria-label="Breadcrumb">...</nav>

<!-- ❌ Two identical "navigation" landmarks — screen reader can't distinguish -->
<nav>...</nav>
<nav>...</nav>
```

### Skip link

Every page must have a skip link as the first focusable element, pointing to `<main>`. Visually hidden until focused.

```html
<a href="#main-content" class="skip-link">Skip to main content</a>
...
<main id="main-content">
```

```css
.skip-link {
  position: absolute;
  top: -100%;
  left: 1rem;
  padding: 0.5rem 1rem;
  background: var(--color-brand);
  color: #fff;
  z-index: 9999;
}
.skip-link:focus { top: 1rem; }
```

---

## Labelling elements

Three attributes label elements for assistive technology. Choose in this order:

### 1. `aria-labelledby` — references visible text already on the page

```html
<h2 id="runs-heading">Recent runs</h2>
<table aria-labelledby="runs-heading">...</table>
```

Prefer this when the label text is already visible — it avoids duplication and keeps visible + accessible names in sync.

### 2. `aria-label` — provides a string label directly

Use when there is no visible text to reference (icon buttons, inputs with only placeholder text, duplicate links).

```html
<!-- Icon-only button -->
<button aria-label="Close modal">
  <svg aria-hidden="true" ...>...</svg>
</button>

<!-- Multiple "View" links on the same page -->
<a href="/runs/42" aria-label="View run my-test-suite">View</a>
<a href="/runs/43" aria-label="View run e2e-smoke">View</a>
```

### 3. `aria-describedby` — supplements the label with additional detail

```html
<input id="env" name="environment" type="text"
       aria-label="Filter by environment"
       aria-describedby="env-hint">
<p id="env-hint" class="text-xs text-slate-500">e.g. ci, staging, production</p>
```

The description is announced after the label and role. Use for hints and error messages, not the primary name.

### `aria-hidden="true"` — remove from accessibility tree

Use on purely decorative elements. **Never** on elements that contain text a sighted user can read, or on focusable elements.

```html
<!-- ✅ Decorative icon alongside visible text -->
<button>
  <svg aria-hidden="true" focusable="false">...</svg>
  Refresh
</button>

<!-- ❌ Hides meaningful content from screen readers -->
<p aria-hidden="true">Error: invalid date format</p>
```

### Screen-reader-only text (`.sr-only`)

For text that should be read but not displayed. Define once in CSS:

```css
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
```

```html
<!-- Loading spinner: icon hidden, text announced -->
<span class="htmx-indicator">
  <svg aria-hidden="true" class="animate-spin ...">...</svg>
  <span class="sr-only">Loading…</span>
</span>

<!-- Button that says "X" — add context for screen readers -->
<button>
  <span aria-hidden="true">×</span>
  <span class="sr-only">Close dialog</span>
</button>
```

---

## State attributes

### `aria-current` — marks the active item in a set

| Value | Use case |
|---|---|
| `page` | Active link in site navigation (current page) |
| `step` | Active step in a multi-step wizard |
| `location` | Current item in a breadcrumb |
| `true` | Active item in any other set |

```html
<!-- Sidebar nav — active page -->
<nav aria-label="Main navigation">
  <a href="/" aria-current="page">Dashboard</a>
  <a href="/runs">Runs</a>
</nav>

<!-- Breadcrumb -->
<nav aria-label="Breadcrumb">
  <ol>
    <li><a href="/">Home</a></li>
    <li><a href="/runs">Runs</a></li>
    <li><a href="/runs/42" aria-current="location">my-test-suite</a></li>
  </ol>
</nav>
```

**Never** use CSS classes alone (e.g. `.active`) to convey current state — they are invisible to screen readers.

### `aria-expanded` — discloses open/closed state

Required on the triggering element (button/link) for disclosures, dropdowns, accordions, and menus. Toggle between `true` and `false` — do not remove the attribute.

```html
<button aria-expanded="false" aria-controls="filters-panel">
  Filters
</button>
<div id="filters-panel" hidden>...</div>
```

```javascript
button.addEventListener('click', () => {
  const expanded = button.getAttribute('aria-expanded') === 'true';
  button.setAttribute('aria-expanded', String(!expanded));
  panel.hidden = expanded;
});
```

### `aria-pressed` — toggle button state

For buttons that stay in an on/off state (unlike momentary actions). Values: `true`, `false`, `mixed`.

```html
<button aria-pressed="false">Mute</button>
```

### `aria-selected` — selected item in a widget

Used on tabs (`role="tab"`), options (`role="option"`), and tree items. The parent container (tablist, listbox) provides context.

```html
<div role="tablist" aria-label="Run details">
  <button role="tab" aria-selected="true"  aria-controls="panel-summary">Summary</button>
  <button role="tab" aria-selected="false" aria-controls="panel-tests">Tests</button>
</div>
```

### `aria-disabled` — disabled without removing from tab order

Use `aria-disabled="true"` instead of the `disabled` attribute when you need a control to remain focusable (e.g. to show a tooltip explaining why it's disabled). Visually style it to look disabled; suppress the action in the event handler.

```html
<button aria-disabled="true" @click="if($el.getAttribute('aria-disabled')==='true') return">
  Delete
</button>
```

---

## Live regions

Live regions announce dynamic content changes to screen readers without requiring the user to move focus. Essential for HTMX-driven applications.

### `aria-live`

| Value | Behaviour | Use when |
|---|---|---|
| `polite` | Waits for current speech to finish, then announces | Filter results, pagination, non-urgent status |
| `assertive` | Interrupts current speech immediately | Critical errors that block the user |
| `off` (default) | No announcement | — |

**Always prefer `polite`.** `assertive` is for genuine emergencies — overuse trains users to ignore it.

```html
<!-- Filter results swap target — announces when content changes -->
<div id="run-list" aria-live="polite">
  <!-- HTMX swaps content here -->
</div>

<!-- Error banner — time-sensitive, blocking -->
<div role="alert" aria-live="assertive">
  Session expired. Please log in again.
</div>
```

### `role="alert"` and `role="status"`

Shorthand alternatives to `aria-live`:

| Role | Equivalent | Use for |
|---|---|---|
| `role="alert"` | `aria-live="assertive"` + `aria-atomic="true"` | Errors, warnings requiring immediate attention |
| `role="status"` | `aria-live="polite"` + `aria-atomic="true"` | Confirmations, non-critical updates ("Saved") |
| `role="log"` | `aria-live="polite"` + `aria-atomic="false"` | Append-only streams (chat, activity feeds) |

```html
<!-- Field-level save confirmation -->
<span role="status" id="name-status"></span>

<!-- Validation error -->
<span role="alert" id="name-error"></span>
```

### `aria-atomic`

Controls whether the entire region or only changed nodes are announced.

- `aria-atomic="true"` — re-read the entire region on any change (good for short status messages)
- `aria-atomic="false"` (default) — announce only the changed nodes (good for append-only feeds)

### `aria-busy`

Signals that a region is being updated. Screen readers may delay reading until `aria-busy="false"`.

```html
<div id="run-list" aria-live="polite" aria-busy="false">...</div>
```

Set to `"true"` before the HTMX request starts, remove (or set to `"false"`) after content settles:

```typescript
// In src/client/app.ts — wire globally alongside initFlowbite()
document.addEventListener('htmx:beforeRequest', (e) => {
  (e.detail.target as HTMLElement).setAttribute('aria-busy', 'true');
});
document.addEventListener('htmx:afterSettle', (e) => {
  (e.detail.target as HTMLElement).removeAttribute('aria-busy');
});
```

### Live region gotchas

- **Must exist in the DOM on page load.** A live region injected via HTMX is not announced. The element must be present (even if empty) before content is swapped into it.
- **Avoid nesting live regions.** Inner and outer regions can produce double announcements.
- **Do not move focus into a live region** after a swap — let the live region announce, then the user navigates if they choose.

---

## Form accessibility

### Every input must have a label

```html
<!-- ✅ Explicit <label> -->
<label for="env-filter">Environment</label>
<input id="env-filter" name="environment" type="text">

<!-- ✅ aria-label when visible label is not possible -->
<input id="search" name="q" type="search" aria-label="Search runs">

<!-- ❌ Placeholder only — disappears when user types, not a label -->
<input name="environment" placeholder="Filter by environment">
```

### Group related controls with `<fieldset>` and `<legend>`

```html
<fieldset>
  <legend>Filter by status</legend>
  <label><input type="radio" name="status" value="passed"> Passed</label>
  <label><input type="radio" name="status" value="failed"> Failed</label>
</fieldset>
```

For non-radio button groups (e.g. status tab buttons), use `role="group"` with `aria-label`:

```html
<div role="group" aria-label="Filter runs by status">
  <button aria-pressed="false" ...>All</button>
  <button aria-pressed="false" ...>Passed</button>
  <button aria-pressed="false" ...>Failed</button>
</div>
```

### Validation errors

Associate errors with the field via `aria-describedby`. Use `aria-invalid="true"` on invalid inputs.

```html
<input id="from-date" name="from" type="text"
       aria-label="Start date"
       aria-describedby="from-date-error"
       aria-invalid="true">
<span id="from-date-error" role="alert">
  Date must be in YYYY-MM-DD format
</span>
```

Clear `aria-invalid` and empty the error element when the value becomes valid.

### Required fields

```html
<input id="name" name="name" type="text"
       aria-label="Project name"
       aria-required="true"
       required>
```

Use both `required` (native validation) and `aria-required="true"` (screen reader announcement).

---

## Interactive widget roles

### Tabs

```html
<div role="tablist" aria-label="Run detail sections">
  <button role="tab" id="tab-summary"
          aria-selected="true"
          aria-controls="panel-summary">
    Summary
  </button>
  <button role="tab" id="tab-tests"
          aria-selected="false"
          aria-controls="panel-tests"
          tabindex="-1">
    Tests
  </button>
</div>

<div role="tabpanel" id="panel-summary" aria-labelledby="tab-summary">
  ...
</div>
<div role="tabpanel" id="panel-tests" aria-labelledby="tab-tests" hidden>
  ...
</div>
```

Keyboard: Tab moves into tablist; arrow keys move between tabs; Enter/Space selects.

### Dialog (modal)

```html
<div role="dialog"
     aria-modal="true"
     aria-labelledby="dialog-title"
     id="run-detail-modal">
  <h2 id="dialog-title">Run detail — my-test-suite</h2>
  <button aria-label="Close dialog">×</button>
  <!-- modal content -->
</div>
```

Required behaviour:
- On open: move focus to the first focusable element inside the dialog (or the dialog itself if no interactive elements precede the content)
- Trap focus: Tab and Shift+Tab cycle only within the dialog
- On Escape: close the dialog and return focus to the trigger that opened it
- `aria-modal="true"` hides background content from some screen readers; also apply `inert` to the rest of the page for full support

```typescript
function openDialog(dialog: HTMLElement, trigger: HTMLElement) {
  dialog.removeAttribute('hidden');
  document.body.querySelectorAll<HTMLElement>(':not(dialog)').forEach(el => {
    if (!dialog.contains(el)) el.inert = true;
  });
  const first = dialog.querySelector<HTMLElement>('button, [href], input, [tabindex="0"]');
  first?.focus();

  function trapFocus(e: KeyboardEvent) {
    if (e.key === 'Escape') { closeDialog(); return; }
    if (e.key !== 'Tab') return;
    const focusable = [...dialog.querySelectorAll<HTMLElement>(
      'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    )];
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function closeDialog() {
    dialog.setAttribute('hidden', '');
    document.body.querySelectorAll<HTMLElement>('[inert]').forEach(el => el.inert = false);
    dialog.removeEventListener('keydown', trapFocus);
    trigger.focus();
  }

  dialog.addEventListener('keydown', trapFocus);
}
```

### Data tables

```html
<table>
  <caption>Test run results — 24 runs found</caption>
  <thead>
    <tr>
      <th scope="col">Run name</th>
      <th scope="col">Status</th>
      <th scope="col">Environment</th>
      <th scope="col">Started</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><a href="/runs/42" aria-label="View run my-test-suite">my-test-suite</a></td>
      <td><span class="badge-pass">Passed</span></td>
      <td>ci</td>
      <td><time datetime="2025-03-15T10:30:00Z">15 Mar 2025</time></td>
    </tr>
  </tbody>
</table>
```

- `<caption>` describes the table (like a label); include row count when dynamic
- `scope="col"` on `<th>` associates headers with columns; use `scope="row"` for row headers
- `<time datetime="...">` for machine-readable dates
- Wrap in a `<div class="overflow-x-auto">` for responsive scrolling; the table's accessible name remains intact

---

## Focus management

### Default rule: do not move focus after HTMX swaps

For filter updates, pagination, and live region refreshes, **do not move focus**. The live region announces the change; moving focus is disorienting.

### When to move focus

| Scenario | Where to move focus |
|---|---|
| Modal opens | First focusable element inside the dialog |
| Modal closes | The button/link that opened it |
| Page-level navigation (full page swap) | The `<h1>` of the new content, or `<main>` |
| Inline form submission that replaces the form with a confirmation | The confirmation message container |
| Alert/error banner inserted above the form | The banner itself (if `role="alert"` it auto-announces; manual focus optional) |

### Programmatic focus

An element must have `tabindex="-1"` to receive programmatic focus if it is not natively focusable:

```html
<div id="confirm-message" tabindex="-1" role="status">
  Your settings have been saved.
</div>
```

```typescript
document.getElementById('confirm-message')?.focus();
```

### `tabindex` rules

| Value | Effect |
|---|---|
| omitted | Natural tab order (only natively focusable elements) |
| `0` | Adds element to tab order at its DOM position |
| `-1` | Focusable programmatically only; excluded from tab order |
| `> 0` | **Never use** — creates an unpredictable tab order that breaks keyboard navigation |

---

## Keyboard navigation patterns

Every interactive element must be operable by keyboard alone.

### Basic expectations

| Key | Expected action |
|---|---|
| Tab | Move focus to next focusable element |
| Shift+Tab | Move focus to previous focusable element |
| Enter | Activate a link, submit a form, activate a button |
| Space | Activate a button (not links); toggle checkboxes |
| Escape | Close modals, dismiss dropdowns, cancel operations |
| Arrow keys | Navigate within composite widgets (tabs, menus, listboxes, radio groups) |

### Composite widget keyboard model

Within a widget (tablist, menu, listbox), use **roving tabindex** so only one element in the group is in the tab order at a time:

```typescript
// Tablist example — arrow keys move between tabs; only active tab is tabindex=0
tabs.forEach((tab, i) => {
  tab.addEventListener('keydown', (e) => {
    let next: HTMLElement | null = null;
    if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length] ?? null;
    if (e.key === 'ArrowLeft')  next = tabs[(i - 1 + tabs.length) % tabs.length] ?? null;
    if (e.key === 'Home')       next = tabs[0] ?? null;
    if (e.key === 'End')        next = tabs[tabs.length - 1] ?? null;
    if (next) {
      tabs.forEach(t => t.setAttribute('tabindex', '-1'));
      next.setAttribute('tabindex', '0');
      next.focus();
    }
  });
});
```

For **status filter tab groups** that use HTMX buttons (not a true tablist), `role="group"` + individual `tabindex="0"` on each button is sufficient — they don't need roving tabindex because they are not a composite widget.

---

## Reduced motion

Respect the user's motion preferences for animations:

```css
/* In input.css — apply globally */
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

For JavaScript-driven animations (Alpine transitions, HTMX indicator fades), check the preference before applying:

```typescript
const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if (!prefersReducedMotion) {
  element.classList.add('animate-spin');
}
```

---

## Reflow & target size (WCAG 2.2 AA)

Two success criteria make mobile-first non-optional. Both are AA and both are commonly missed.

### SC 1.4.10 Reflow

Content must be usable at **320 CSS px width (≈400% zoom on a 1280px screen) without two-dimensional scrolling** — the user should never have to scroll horizontally to read a vertical block of content. Allowed exceptions are parts that genuinely need 2-D layout: data tables, code blocks, maps, diagrams (give those their own `overflow-x-auto` container so the *page* still doesn't scroll sideways).

In practice you satisfy Reflow by authoring mobile-first and passing the no-horizontal-overflow gate:

```ts
// At each width in {360, 768, 1280} (and 320 for the strict SC check):
const overflow = await page.evaluate(
  () => document.documentElement.scrollWidth - window.innerWidth,
);
expect(overflow).toBeLessThanOrEqual(0);
```

A wide element inside `overflow-hidden` clips silently and won't register here — pair the gate with a check that wide content wrappers are `overflow-x-auto`, never `overflow-hidden`. See [`../languages/css/responsive.md`](../languages/css/responsive.md) and [`../testing/verification-cookbook.md`](../testing/verification-cookbook.md).

### SC 2.5.8 Target Size (Minimum)

Interactive targets must be at least **24×24 CSS px** (AA floor). The house preferred size on primary action rows is **44×44** (the conventional touch target). Apply it to buttons, nav items, and table-row actions — bake the minimum into the component class, not per-template. Exceptions in the SC (inline links in a sentence, native UA controls, spacing-equivalent targets) are narrow; default to meeting the size.

### SC 1.4.4 Resize Text / pinch-zoom

Never ship `<meta name="viewport" …>` with `maximum-scale=1` or `user-scalable=no` — disabling zoom is a 1.4.4 failure. The viewport meta must be `width=device-width, initial-scale=1` (no fixed width). See [`../languages/css/responsive.md`](../languages/css/responsive.md) §"Viewport meta".

---

## HTMX-specific patterns

### Live region must pre-exist

The swap target must be in the DOM **before** the first HTMX request. Inserting a `aria-live` region via HTMX means the browser has not registered it as a live region yet — announcements will be silently dropped.

```html
<!-- ✅ Pre-existing in the page shell (layout or page template) -->
<div id="run-list" aria-live="polite" aria-busy="false">
  <%~ await includeAsync('../partials/runs.eta', it) %>
</div>

<!-- ❌ Injected by HTMX — the live region is never registered -->
<!-- (do not do this) -->
```

### Flowbite components

Flowbite initialises its JS widgets (dropdowns, modals, tooltips) on `htmx:afterSettle`. Its components include built-in ARIA attributes (e.g. `aria-expanded` on dropdowns, `role="dialog"` on modals). When using a Flowbite component:

- Do not add duplicate ARIA attributes that Flowbite already manages
- Do add any ARIA attributes Flowbite does **not** manage (e.g. `aria-label` on nav, `aria-current` on active links — Flowbite does not know which link is active)

### `hx-confirm` dialogs

The native browser `confirm()` used by `hx-confirm` is not keyboard-accessible in all browsers and cannot be styled. For any confirmation that involves destructive actions, replace it with a proper `role="dialog"` modal pattern instead.

---

## Quick-reference checklist

For every new page or component, verify:

**Landmarks**
- [ ] Page has exactly one `<main>` with `id` for skip link
- [ ] Skip link is the first focusable element
- [ ] All `<nav>` elements have `aria-label`
- [ ] `<section>` elements used as regions have `aria-labelledby`

**Labelling**
- [ ] Every `<input>`, `<select>`, `<textarea>` has an associated `<label>` or `aria-label`
- [ ] Icon-only buttons have `aria-label`
- [ ] Ambiguous links have `aria-label` (no repeated "View", "Edit", "Delete" without context)
- [ ] Decorative SVGs have `aria-hidden="true"`

**State**
- [ ] Active nav link has `aria-current="page"`
- [ ] Expandable controls have `aria-expanded` (toggled on open/close)
- [ ] Toggle buttons have `aria-pressed`
- [ ] Invalid fields have `aria-invalid="true"` and `aria-describedby` pointing to error

**Live regions**
- [ ] HTMX swap targets that update without navigation have `aria-live="polite"`
- [ ] Live regions are in the DOM on page load, not injected later
- [ ] `aria-busy` is set before requests and cleared after settle
- [ ] Error banners use `role="alert"` (not `role="status"`)

**Keyboard**
- [ ] All interactive elements reachable by Tab
- [ ] No `tabindex` values greater than 0
- [ ] Modals trap focus and return it on close
- [ ] Escape closes modals and dismisses dropdowns

**Forms**
- [ ] Required fields have `aria-required="true"` and `required`
- [ ] Validation errors are associated via `aria-describedby`
- [ ] Related controls grouped with `<fieldset>`/`<legend>` or `role="group"`/`aria-label`

**Motion**
- [ ] `prefers-reduced-motion` respected in CSS and JS animations

**Reflow & target size (mobile-first)**
- [ ] No horizontal page overflow at 360 / 768 / 1280 (SC 1.4.10 Reflow); wide content in `overflow-x-auto`, never `overflow-hidden`
- [ ] Primary tap targets ≥ 44×44 CSS px (SC 2.5.8 floor 24×24)
- [ ] `<meta viewport>` = `width=device-width, initial-scale=1` — no fixed width, no `maximum-scale`/`user-scalable=no` (SC 1.4.4)
- [ ] Primary nav reachable below `md` (drawer + hamburger, labelled, focus-trapped)
