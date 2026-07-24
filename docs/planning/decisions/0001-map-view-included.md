# 0001 — Geographic map view is included in the /all-groups directory

**Status:** Accepted (2026-07-24)
**Sources:** Issue #196, upstream https://git.drupalcode.org/project/groupsdrupalorg/-/issues/3578797

## Decision

The `/all-groups` directory offers a **Map** presentation variant alongside Cards and
Compact-list. Map is powered by the geofield module + locally-vendored Leaflet
(no external CDN calls) and reads groups' `field_location` coordinates.

## Rationale

The upstream docs-repo issue references a map view as a planned decision. Our
implementation lands it as a first-class variant (not "coming (soon)") so
demo visitors can experience geographic discovery immediately.

Vendoring Leaflet locally keeps the demo self-contained: no runtime dependency
on a CDN, no third-party tracking, and the audit trail lives in this repo.

## Consequences

- One new field (`field_location` on the community_group type) with a corresponding
  seed step.
- `web/libraries/leaflet/` is checked into the source tree (small footprint).
- The Directory variant switcher gets a third option; downstream UI copy names
  Map alongside Cards and Compact-list.
- Zero-CDN acceptance criterion (asserted in E2E): headless Chromium sees no
  external tile-server hits when the Map variant loads.

## Implementation

Shipped in issue #125 / PR #189 (`546d4de`) as SC-6.
