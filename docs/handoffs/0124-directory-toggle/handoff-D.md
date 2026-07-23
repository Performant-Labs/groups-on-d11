# Handoff-D: Phase 2 - Directory compact-list vs cards toggle (#124 SC-5)

**Date:** 2026-07-23
**Branch:** 124-directory-toggle
**Mode:** (a) generated low-fi
**Wireframe:** docs/handoffs/0124-directory-toggle/wireframe.md

## Screens & states covered

- **Surface 1 — switcher mounted on `/all-groups`:** default (Cards selected), Compact-list
  selected (live, no reload), no-JS fallback (`?variant=compact` link, "(current)" text marker),
  and the defensive `?variant=map` fallback-to-first-available state. Confirms option order
  (Compact list / Cards / Map (soon)), the "Viewing:" label position, and the ⓘ tooltip copy
  (already-shipped `showcase.switcher.directory.layout` HelpText string — no new copy).
- **Surface 2 — compact row layout:** many (typical filtered result set), empty (no groups match
  filters — reuses the view's existing empty-region copy, flagged as an open question below since
  it may not distinguish "no groups ever" from "no groups match this filter"), one (single-row,
  no special casing needed), and error/edge (missing `field_group_type` renders truthful
  "[Uncategorized]", not a blank badge; unavailable member count renders "— members" rather than a
  false "0 members" — flagged as an open question on whether F can even make that distinction).
  Horizontal field order fixed: name (linked) / type badge / member count / visibility badge.
  Visibility badge specified as TEXT (`[Open]`/`[Moderated]`/`[Invite only]`), not color-only,
  matching the `/showcase` catalog's `[ live ]`/`[ coming ]` badge convention.
- **Surface 3 — wrapper CSS-variant contract:** confirms the attribute
  (`data-do-directory-variant="cards"|"compact"`) lives on the view's `.view-content` element (not
  a new sibling wrapper, not per-row), default/absent = cards exactly as today, compact CSS only
  matches the `compact` value. Flags the JS-to-attribute wiring mechanism as an F implementation
  choice, not a visual decision (open question #1).
- **A11y contract table:** confirms zero new keyboard/focus code (SC-F1's `do_showcase.switcher.js`
  + focus CSS reused verbatim); the only NEW AA surface this story introduces is contrast on the
  compact-row visibility badge text, which is a text-label cue by construction so it cannot also
  fail the non-color-status requirement.
- **Fallback behavior:** explicitly calls out that the Twig/hook layer (not JS) must resolve
  `?variant=` server-side on initial render, mirroring `VariantSwitcher::resolveSelection()`'s
  first-available fallback rule, with the same `url.query_args:variant` cache context requirement
  already established by SC-F1.
- **Cross-page persistence with `/showcase`:** documents the shared `doShowcase.variant.directory.layout`
  sessionStorage key as intended UX (pick compact on `/showcase`, land on `/all-groups` already
  compact) and calls out that U should test this explicitly, per the story's own instruction.

## Existing components/patterns reused

- SC-F1's `VariantSwitcher::build()`, its Twig template, and `do_showcase.switcher.js` — reused
  verbatim, same `instance_id` (`directory.layout`), so tooltip copy and sessionStorage persistence
  come for free.
- The `/showcase` stub's exact three-option list/order (`Compact list`/`Cards`/`Map (soon)`).
- The `/showcase` catalog's non-color bracketed-text badge convention (`[ live ]`/`[ coming ]`),
  reused for both the new type badge and visibility badge.
- `VisibilityTooltip.php`'s three visibility values (`open`/`moderated`/`invite_only`) as the
  source of truth for the visibility badge's text labels.
- The existing view's exposed-filter form, pager, and empty-region markup — unchanged pass-through
  in both variants.

## Open questions for approval

1. **JS-to-wrapper-attribute wiring mechanism** — should the shared `do_showcase.switcher.js` gain
   a small generic "on select, toggle a caller-supplied wrapper attribute" hook, or should F use a
   CSS `:has()` selector keyed on the switcher's own `aria-checked` DOM state to avoid touching a
   file other instances/stories depend on? This is an implementation choice, but it determines
   whether this story touches shared JS — recommend O/human confirm the preferred approach before
   F starts.
2. **Filtered-to-zero-results empty copy** — the current view config has one empty-region string
   ("No groups yet / Be the first to start a community"), which may not read truthfully when
   groups exist but none match the current filter. This wireframe reuses whatever the view renders
   today unchanged in both variants (not a regression this story introduces), but flags it in case
   O wants it fixed as part of this story vs. filed as a follow-up.
3. **Member count "unavailable" vs. "zero"** — accept "0 members" as a simplification if F's Views
   aggregation can't distinguish a genuine zero from an aggregation failure, unless O objects.

## Approval

[To be filled by O: "Approved by operator <ISO timestamp>" — D does not self-approve.]
