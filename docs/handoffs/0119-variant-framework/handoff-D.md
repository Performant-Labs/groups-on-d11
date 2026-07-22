# Handoff-D: Phase 2 - SC-F1 Variant framework (switcher, /showcase, POC ribbon)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Mode:** (a) generated low-fi
**Wireframe:** `docs/handoffs/0119-variant-framework/wireframe.md` (ASCII, structure-only; no
production CSS/color values — this is a low-fi gate, not a design-system deliverable)

## Screens & states covered

### Surface 1 — labeled variant-switcher device
- **Default** (one option selected) — selection conveyed by a leading `●` glyph + `aria-checked`,
  never color alone; ⓘ tooltip trigger attaches once per switcher instance (matches `do_chrome`'s
  one-ⓘ-per-widget-wrapper pattern, not one per option).
- **Focus** — visible focus ring on the option with DOM focus, independent of and visually
  distinguishable from the *selected* option.
- **Unavailable/degraded** — the unavailable option stays visible with "(soon)" appended
  (truthful, not silently hidden or a dead click target), `aria-disabled`, selection falls back to
  the first available option if `current` names an unavailable one.
- **No-JS** — plain `?variant=` query-param links, current one marked in text, not just visually.

### Surface 2 — `/showcase` tour page
- **Default (many)** — all seven required entries present: discovery ranking, directory
  presentation, membership models, group-type homepages, stream model, private-group reveal
  (#134), and the persona-switcher entry (#120) naming all four public personas (Anonymous /
  Elena Garcia / Maria Chen / Moderator). Each entry: title, one-sentence decision framing, a
  `[ live ]` / `[ coming ]` text status badge (non-color cue), and a deep-link only where live.
- **Empty** — documented for render-array completeness (`#empty` truthful copy), not reachable in
  normal operation since the catalog is a seven-entry code constant.
- **Error** — catalog build fails: friendly non-technical copy, no stack trace, standard Drupal
  watchdog-logs/user-sees-message convention (no new error mechanism invented).

### Surface 3 — site-wide POC ribbon
- **Default** — identical copy/behavior for anonymous and authenticated, links to `/showcase`, a
  real `<button aria-label="Dismiss demo banner">` for the ✕, independently keyboard-reachable
  from the `/showcase` link.
- **Focus** — visible focus ring on the dismiss button, same treatment as Surface 1.
- **Dismissed** — ribbon absent, no layout shift beyond its own height collapsing, persists
  client-side (cookie/localStorage), no server session.

## Existing components/patterns reused
- `do_chrome`'s tooltip-surface pattern: one ⓘ trigger per widget wrapper (not per option),
  `data-do-tooltip` + plain-text `#description` fallback dual-channel (from `VisibilityTooltip.php`
  and `HelpText.php`) — reused verbatim for the switcher's tooltip, no new tooltip mechanism.
- `DoChromeHooks`'s single global `page_attachments` attach point — direct analog for the ribbon's
  injection point (site-wide chrome attached once, not re-attached per page).
- `do_notifications`/`do_discovery`'s `ControllerBase` + `.routing.yml` pattern — reused for
  `/showcase`'s plain title/intro/list render-array shape; no new template language.
- Drupal's native disabled-control and focus-ring conventions — no new focus/disabled mechanism
  invented; F implements against whatever AA-authoring-practice pattern already exists for a
  disabled control elsewhere in the codebase, or defaults to `aria-disabled` + `tabindex="-1"` if
  none exists.

## Open questions for approval
1. **Ribbon re-entry point after dismissal.** This story's acceptance criteria don't require one
   (the `/showcase` route stays reachable via normal nav regardless of ribbon state), so the
   wireframe ships without a "show demo banner again" affordance. Flagging in case the operator
   wants one — if so, it's a small addition (e.g. a persistent footer link), not a redesign.
2. **Ribbon placement: fixed-top vs. a corner.** The brief says "fixed top or corner" — wireframe
   shows fixed-top (matches the issue's own "fixed banner" framing and is the more conventional
   pattern for a site-wide notice), but this is F's implementation call as long as it doesn't cover
   primary nav or reshuffle nav DOM (the `nav.spec.ts` non-regression constraint). Not blocking
   sign-off — noted so the operator can override if they have a placement preference.
3. **Disabled-option ARIA pattern for the switcher's "unavailable" state** — wireframe specifies
   `aria-disabled="true"` + removed from tab order as the default, but defers to whatever pattern
   an existing disabled control in this codebase already uses, if any (F to confirm during
   implementation). Not a design ambiguity, just an implementation-detail dependency worth naming.

None of the three are blocking — all have a stated default that preserves the brief's constraints;
they're surfaced for optional operator override, not because the wireframe is ambiguous without an
answer.

## Approval
[To be filled by O: "Approved by operator <ISO timestamp>" — D does not self-approve.]

---
Ready for operator sign-off.
