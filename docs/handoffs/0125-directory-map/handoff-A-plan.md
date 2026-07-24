# Handoff-A: Phase 3 — #125 SC-6 Directory map view  (up-front plan review, re-review)

**Date:** 2026-07-24
**Branch:** 125-directory-map
**Brief reviewed:** docs/handoffs/0125-directory-map/brief.md (amended)
**Reuse map:** docs/handoffs/0125-directory-map/survey.md (unchanged)
**Wireframe:** docs/handoffs/0125-directory-map/wireframe.md (unchanged)
**Verdict:** PASS

## Summary
All three findings from the prior review are adequately resolved by the brief amendments and the
Phase-3 decisions.md entry. The blocker (gitignored `/web/libraries/`) is closed by adopting the
existing source→assemble discipline — tracked SOURCE under `docs/groups/libraries/leaflet/`, a new
`scripts/ci/assemble-libraries.sh` sibling script, and three `.github/workflows/test.yml` wiring
lines matching each existing `assemble-config.sh` call site. Warns #2 and #3 are resolved by
declaring a bare `leaflet:` library entry that `directory-map:` depends on, and by adding an
explicit boot-from-scratch verification step plus `dependencies.module: [geofield]` on both
geofield YAML files. Plan is ready for T-red.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| — | — | — | — | Plan is consistent with existing patterns; all three prior findings resolved. | — |

### Resolution audit (against prior review)

**Prior Finding #1 (BLOCK — gitignored vendor path) → RESOLVED.**
Brief "Owned files" now names both sides of the divide explicitly: `docs/groups/libraries/leaflet/**`
as tracked SOURCE and `web/libraries/leaflet/**` as gitignored TARGET, with `scripts/ci/assemble-libraries.sh`
as the copy step. This exactly mirrors the `docs/groups/config/` → `config/sync/` and
`docs/groups/modules/` → `web/modules/custom/` pattern the project override calls out as canonical.
Three CI wiring points in `.github/workflows/test.yml` (one per existing `assemble-config.sh`
call site) close the loop so CI cannot boot without Leaflet vendored. Decisions.md correctly
notes the script is a plain idempotent copy — no composer/vendor autoload dependency, so ordering
against `composer install` is a non-issue. This is the right strategy and the right layering.

**Prior Finding #2 (warn — leaflet library entry shape) → RESOLVED.**
Brief now specifies TWO library entries in `do_showcase.libraries.yml`: a bare `leaflet:` entry
pointing at `/libraries/leaflet/leaflet.js` + `.css`, and `directory-map:` declaring
`- do_showcase/leaflet` + `- do_showcase/switcher` as dependencies. This is the Drupal library-
composition idiom, matches the peer `directory-compact` entry's shape/dependency pattern, and
leaves the leaflet asset entry reusable by any future map consumer (SC-6b overview map, admin
geocoding UI, etc.) without a second vendored path.

**Prior Finding #3 (warn — geofield dep + fresh-boot verification) → RESOLVED.**
Decisions.md confirms `do_showcase.info.yml` gains only `- geofield` (not `- geofield_map`), and
that both `field.storage.group.field_group_location.yml` and
`field.field.group.community_group.field_group_location.yml` will declare explicit
`dependencies.module: [geofield]` — mirroring the sibling `field.storage.group.field_group_location_text.yml`'s
explicit `dependencies.module: [group]` precedent. F's handoff will include the boot-verification
step (fresh `drush si` + `drush cim` on a clean DB, after `assemble-config.sh` +
`assemble-libraries.sh`), which is the correct end-to-end proof that config-import module
dependency wiring holds.

## Notes for O
None. Verdict is PASS; hand off to T for red-tests.

## Patterns referenced
- `.gitignore:9` (`/web/libraries/` — confirming the assemble-target rationale)
- `scripts/ci/assemble-config.sh` (sibling pattern that `assemble-libraries.sh` mirrors)
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml` (peer library-composition idiom for the two-entry split)
- `docs/groups/config/field.storage.group.field_group_location_text.yml` (`dependencies.module:` precedent)
- `docs/handoffs/0125-directory-map/decisions.md` Phase 3 amendment entry (resolution record)
