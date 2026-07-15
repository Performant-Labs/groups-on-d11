# Pattern: Internal Tool UI Standards

Conventions for internal/admin tools built with the `@pl-audit/*` ui-kit or
any stdlib-HTTP + HTMX server (no front-end framework). These are the baseline
expectations — deviating requires an explicit reason.

---

## Buttons — never use browser defaults

**Never ship a bare `<button>` or `<input type="submit">` with no styling.**
The browser default (grey box, system font, no radius) signals an unfinished UI
and erodes trust in the tool, even in internal use.

Every button must have at minimum:
- A background colour from the project's design token set (or a sensible hardcoded
  value if tokens aren't available).
- Visible padding, a border-radius ≥ 4px, and a legible contrast ratio.
- A `:hover` / `:focus-visible` state so keyboard and mouse users see feedback.

### In `@pl-audit/ui-kit`

Use the `.uik-btn` class (primary) or `.uik-btn-secondary` (secondary/cancel).
Never add a bare `<button type="submit">` to a form — always attach at least
`.uik-btn`.

```html
<!-- ✅ correct -->
<button type="submit" class="uik-btn">Save</button>
<button type="button" class="uik-btn-secondary">Cancel</button>

<!-- ❌ wrong — browser default styling -->
<button type="submit">Save</button>
```

### In other stdlib-HTTP / HTMX apps

If `ui-kit` is not available, add a minimal button reset in the page's `<style>`:

```css
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 18px;
  border: none;
  border-radius: 6px;
  background: #1a56db;
  color: #fff;
  font: inherit;
  font-size: 0.9rem;
  cursor: pointer;
}
.btn:hover  { background: #1648c0; }
.btn:focus-visible { outline: 2px solid #1a56db; outline-offset: 2px; }
```

---

## Forms

- Labels are always visible (no placeholder-only labels).
- Input focus ring must be visible (do not remove `outline` without a replacement).
- Saved/error feedback must appear inline near the triggering control, not only
  in a page-level flash that may scroll out of view.

---

## Applicability

These standards apply to:
- `@pl-audit/app` (the Site-Quality Auditor GUI)
- Any internal admin UI built in this org
- Wizard flows (see `setup-wizard.md`)

They do **not** apply to public-facing Drupal/DripYard themes, which have their
own design system (see `themes/dripyard-guidance.md`).
