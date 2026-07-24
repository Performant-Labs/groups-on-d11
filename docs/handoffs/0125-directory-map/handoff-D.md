# Handoff-D: Phase 2 - SC-6 Directory map view

**Date:** 2026-07-24
**Branch:** 125-directory-map
**Mode:** (a) generated low-fi
**Wireframe:** docs/handoffs/0125-directory-map/wireframe.md

## Screens & states covered

- **Surface 1 — Switcher + map region on `/all-groups`:**
  - Default (Cards selected, Map now live not "(soon)").
  - Map selected, many groups with a location (typical POC state — 4 seeded groups plot).
  - Map selected, partial state — only some groups have a location ("Showing N of M groups with a
    location" truthful caption; unplottable groups are silently absent, no dead/placeholder pin).
  - Map selected, zero groups have a location (defensive edge case — not reachable with current
    seed data, flagged as open question re: whether to implement now).
  - Map selected, filtered to zero groups overall — reuses the existing view empty-region copy
    verbatim, rendered inside the map container box instead of a blank grey rectangle.
- **Surface 2 — Keyboard/AA behavior:** map container `aria-label` (states count + signals the
  keyboard path exists), SR-only fallback `<ul>` of "group name — city" links (visible-on-focus,
  not `display:none`), reused focus-visible token from `directory-compact.css`.
- **Surface 3 — Wrapper contract:** `data-do-directory-variant="map"` on `.views-element-container`
  (identical element/attribute SC-5 already established), CSS hides `.view-content` rather than
  removing rows from the DOM (JS still needs `data-do-location-lat/lng` off each row).
- **Non-goals confirmation:** no custom icons, no clustering, no live tiles, no "add my location",
  no map-side filtering, no geocoding UI — each explicitly checked against the mock.

## Existing components/patterns reused

- SC-F1 `VariantSwitcher` three-option radiogroup device (0119 wireframe Surface 1) — reused
  verbatim; only `map`'s `available` flag and label text change.
- SC-5 `data-do-directory-variant` wrapper-attribute contract and "hide inactive variant via CSS,
  keep rows in DOM" pattern (`directory-compact.css`) — extended with a third value, not
  redesigned.
- `HelpText::get('showcase.switcher.directory.layout')` — verbatim, already shipped copy naming
  "Map plots groups geographically."
- `directory-compact.css`'s focus-visible outline token — reused for the SR-only fallback list.
- Existing `views.view.all_groups.yml` empty-region copy — reused verbatim for the
  filtered-to-zero-overall state.
- `field_group_location_text` (already-existing free-text city field) — reused as the fallback
  list's "— city" copy source; no reverse-geocoding introduced.

## Open questions for approval

1. Hover/focus `title` attribute preview on markers before click-to-navigate — not requested by
   the brief, recommend as a zero-cost nicety but flagging since it's outside "exactly what's
   asked."
2. `role="application"` on the map container — needs F to confirm Leaflet 1.9.4's own container
   markup doesn't already carry conflicting ARIA before assuming this wireframe's exact HTML shape.
3. Zero-groups-have-a-location defensive state — not reachable with the current 4-seed dataset.
   Confirm whether F should implement the friendlier centered-message branch, or whether "Showing
   0 of 4 groups with a location" (caption only, no special empty-map message) is an acceptable
   POC simplification.

## Approval
[To be filled by O: "Approved by operator <ISO timestamp>" — D does not self-approve.]
