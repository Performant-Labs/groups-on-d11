# Handoff-A: Phase 3 - #198 Map-copy fix  (up-front plan review)

**Date:** 2026-07-24
**Branch:** 198-map-copy-fix
**Brief reviewed:** docs/handoffs/0198-map-copy-fix/brief.md
**Reuse map:** brief.md §"Reuse & Analogous-Feature map" (anchor: #202, ba564ec)
**Wireframe:** N/A (no UI surface change; user-visible string only)
**Verdict:** PASS

## Summary
Plan is consistent with existing patterns. The proposed test locus
(`tests/src/Unit/ShowcaseCatalogTest.php`) is exactly where the analogous per-entry
`decision_sentence` assertion already lives — `testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence`
(lines 265-276) is a 1:1 template for the new assertion. No duplication, no orphan copy elsewhere,
no existing assertion pins the OLD phrase.

## Findings

Plan is consistent with existing patterns. No blocks, no warns.

## Answers to A's questions

1. **Test locus — extend `ShowcaseCatalogTest`?** Yes. The class already carries per-entry
   `decision_sentence` substring assertions (see `testStreamModelEntryIsLive…` L265-276 asserting
   `node-content model` / `activity-log model`, and `testPrivateGroupRevealEntryReferencesIssue134`
   L168-173). Adding one more `testDirectoryPresentationEntryNamesMapVariant`-style method fits
   the file's established cadence. A separate copy-parity kernel test would be over-abstraction
   for a one-string regression guard.

2. **Duplication risk in existing suite?** None. Grep for `directory-presentation` +
   `decision_sentence` returns only the `ShowcaseCatalogDirectoryLiveTest` (which pins
   `status`/`route`, not copy) and `ShowcaseCatalogTest::testAllSevenRequiredEntriesArePresent`
   (id-list only). No existing assertion touches the directory-presentation
   `decision_sentence` string content.

3. **Missed stale references?** Confirmed clean. Grep across `docs/groups/` for the phrase
   fragments (`list vs.`, `card layouts`, `list-vs-cards`) matches ONLY the two files the brief
   names (`ShowcaseCatalog.php:52`, `ShowcaseController.php:223`). No Twig template, no Views
   YAML in `docs/groups/config`, no HelpText key, no README/wireframe carries the stale phrase.
   `HelpText.php:169` already correctly names all three variants (per brief §Background).

4. **Will the rewritten decision_sentence break any pinned assertion?** No. Grep for the exact
   OLD phrase `list vs. card layouts` matches ONLY the source file itself — no test file pins
   it. Safe to rewrite. (Trap avoided: unlike #133/#202 where old copy WAS asserted, here the
   original was never test-pinned.)

## Notes for O
None required. PASS.

## Patterns referenced
- `docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php` (lines 265-276, 168-173) — template for the new per-entry decision_sentence assertion.
- `docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseCatalogDirectoryLiveTest.php` — companion per-entry test; confirms the split-file convention (status/route pin vs. copy pin).
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` (lines 30-52) — object being extended; pure data class, no service deps, unit-testable in isolation.
- `docs/groups/modules/do_showcase/src/HelpText.php:169` — reference copy that already names all three variants correctly (the target voice).
- Commit ba564ec (#202) — analogous prior repair; same reuse-first shape.
