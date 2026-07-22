# Handoff-A: Phase 3 - SC-F1 Variant framework (up-front plan review)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Brief reviewed:** docs/handoffs/0119-variant-framework/brief.md
**Reuse map:** docs/handoffs/0119-variant-framework/survey.md (§Reuse & Analogous-Feature map)
**Wireframe:** docs/handoffs/0119-variant-framework/wireframe.md (+ handoff-D.md)
**Verdict:** PASS

## Summary
The plan cleanly follows existing conventions: new `do_showcase` module is justified the same way
`do_chrome` justified being its own module (a distinct object shape, not a grab-bag extension);
`ShowcaseCatalog` mirrors `do_chrome\PermissionMatrix`'s exact shape (plain class,
`StringTranslationTrait`, no service deps, typed array returns); `/showcase` follows the
`do_notifications`/`do_discovery` `ControllerBase` + `.routing.yml` pattern; the ribbon's
`page_attachments` injection point matches `DoChromeHooks::pageAttachments()`'s "single global
attach point" pattern; and the reuse of `do_chrome\HelpText` (append-only) + the `do_chrome/tooltips`
library is correctly scoped as extension, not duplication. The client-side-persistence correction
(Brief-gate B-2) is the architecturally sound call given no anonymous-session pattern exists
anywhere in this codebase. No blocking findings.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | survey.md Reuse map, "Extend-vs-new recommendation" bullet "Session persistence: EXTEND Drupal core's `tempstore.private` service" | consistency between planning docs | This line was written before Brief-gate B-2 corrected the persistence mechanism to client-side cookie/localStorage (server tempstore would bust the anon page cache). The brief.md and wireframe.md both correctly reflect the corrected decision, but survey.md's Reuse map still states the superseded tempstore approach verbatim. If F (or a future re-reader) opens survey.md instead of brief.md first, this stale line could point them at the wrong mechanism. | O should add a one-line strikethrough/correction note in survey.md's Reuse map pointing to brief.md's B-2 resolution as authoritative, so the two documents don't visibly disagree. Not a plan defect — the actual plan (brief + wireframe) already has this right. |
| 2 | warn | `VariantSwitcher` render/service choice vs. `Plugin/Block` (do_profile_stats, do_group_mission) | abstraction level / pattern consistency | The repo's only existing "embeddable, reusable render surface" precedent is the Block plugin (`GroupMissionBlock`, `ContributionStatsBlock`) — site-builder-placed, context-derived (group from route/context). The brief instead proposes a plain service `do_showcase.variant_switcher::build(string $instance_id, array $options, string $current): array`. This is a deliberate and correct divergence, not drift: the switcher's calling code always supplies explicit parameters (instance_id/options/current) rather than deriving state from block context/region placement, and SC-4/5/6/ST-8 need to call it programmatically from inline template/controller code, not from a block region. Flagging only so F documents this reasoning in handoff-F (the "why not a Block plugin" answer should be explicit, since it's the one point where the plan departs from the nearest existing analog) — not a fix, just a documentation nudge. | None required to proceed; F's handoff should note the Block-vs-service distinction in one sentence so Phase 7 anti-dup review doesn't need to re-derive it. |

No block-level findings. Plan is consistent with existing patterns.

## Notes for O
None required — PASS. The one actionable item (finding #1) is a low-cost doc-hygiene fix O can make
directly in survey.md at any point before/independent of F starting; it does not block Phase 4/5.

## Patterns referenced
- `docs/groups/modules/do_chrome/src/PermissionMatrix.php` — direct shape analog for `ShowcaseCatalog`
  (plain class, `StringTranslationTrait`, no DI, typed array-of-arrays with `TranslatableMarkup`).
- `docs/groups/modules/do_chrome/src/Hook/DoChromeHooks.php` — `page_attachments` as the single
  global-chrome attach point; direct analog for the POC ribbon injection.
- `docs/groups/modules/do_chrome/src/Hook/VisibilityTooltip.php` + `src/HelpText.php` — dual-channel
  `data-do-tooltip`/`#description` pattern and the append-only HelpText contract, both correctly
  reused (not duplicated) per the plan.
- `docs/groups/modules/do_notifications/do_notifications.routing.yml` +
  `src/Controller/NotificationSettingsController.php`, `docs/groups/modules/do_discovery/do_discovery.routing.yml`
  — `ControllerBase` + `.routing.yml` + `_permission: 'access content'` convention for `/showcase`.
- `docs/groups/modules/do_group_mission/src/Plugin/Block/GroupMissionBlock.php`,
  `docs/groups/modules/do_profile_stats/src/Plugin/Block/*` — the repo's only existing
  embeddable-render-surface precedent, used as the comparison baseline for the service-vs-block
  question (finding #2).
- `docs/groups/modules/do_chrome/do_chrome.services.yml` — one autowire:false service per hook
  surface, tagged `hook_implementations`; confirms the plan's module/service naming cadence.
- `playwright.config.ts` (`testDir: './tests/e2e'`) — confirms the plan's test-location constraint.
