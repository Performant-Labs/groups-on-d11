# Handoff-T-red: Phase 4 - #198 Docs parity: directory-layout copy vs. shipped Map view

**Date:** 2026-07-24
**Branch:** 198-map-copy-fix
**Brief / wireframe reviewed:** docs/handoffs/0198-map-copy-fix/brief.md, docs/handoffs/0198-map-copy-fix/handoff-A-plan.md (no wireframe — no UI surface change)

## A precondition
Confirmed: A returned PASS on the plan (Phase 3) — `handoff-A-plan.md` verdict PASS, no blocks/warns.

## Tests authored

**`testDirectoryPresentationEntryNamesMapVariant`**
(`docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php`, lines 278-311, appended
after the existing `testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence`)

- **Criterion pinned:** brief.md acceptance criterion — "Existing `ShowcaseCatalogTest` gains ONE
  assertion: directory-presentation entry's decision_sentence mentions Map (or
  'geograph'/'location')."
- **Tier:** Unit (extends the existing `ShowcaseCatalogTest`, a pure-PHP-array unit test with no
  service/container deps — `ShowcaseCatalog` is instantiated directly with a translation stub).
  This is the cheapest sufficient tier: the behavior under test is a static string returned by
  `ShowcaseCatalog::entries()`, not rendering, routing, or a live page — no kernel/functional
  bootstrap needed. Matches the file's established cadence (1:1 template:
  `testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence`, L265-276).
- **What it asserts:** locates the `directory-presentation` entry from `entries()`, casts
  `decision_sentence` to string, and asserts it `assertStringContainsString('Map', …)` AND
  `assertStringContainsString('geograph', …)`.

**Keyword choice — `geograph` (not `location` or `plot`):** `geograph` is the exact root
HelpText.php:169 already uses for this same variant ("Map plots groups geographically" — the
brief's own §Background cites this as already-correct reference copy). Pinning the same root
keeps the two copy surfaces (ShowcaseCatalog's decision_sentence and HelpText's tooltip)
conceptually aligned rather than letting F pick an unrelated synonym that satisfies the letter of
the criterion but drifts from the established voice. `Map` is asserted separately so the fix must
both name the variant and describe its axis — not just one or the other.

## RED confirmation

Command:
```
ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php \
  --filter testDirectoryPresentationEntryNamesMapVariant
```
(Ran `ddev exec bash scripts/ci/assemble-config.sh` first so the assembled
`web/modules/custom/do_showcase/` reflects the test edit — `docker`/`ddev` container namespace
`gm198-mapcopy`, spun up fresh for this worktree since `.ddev/config.yaml` had a stale name copied
from another worktree checkout.)

Output:
```
There was 1 failure:

1) Drupal\Tests\do_showcase\Unit\ShowcaseCatalogTest::testDirectoryPresentationEntryNamesMapVariant
The directory-presentation decision_sentence must name Map as the third variant (#125 SC-6 shipped it live; #198 docs parity).
Failed asserting that 'Compares list vs. card layouts for the group directory — the decision: information density vs. visual scannability.' [UTF-8](length: 117) contains "Map" [ASCII](length: 3).

/var/www/html/web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php:311

FAILURES!
Tests: 1, Assertions: 2, Failures: 1, PHPUnit Deprecations: 14.
```

This is a **valid RED**: the failure is the exact feature assertion (unchanged
`decision_sentence` string does not contain "Map"), not an import/typo/setup error. The failure
message quotes the current live string verbatim — confirms it matches brief.md's cited stale copy
exactly.

**Full-file sanity check** (`--testdox`, no `--filter`): all 12 pre-existing tests in
`ShowcaseCatalogTest` still pass (`Tests: 13, Assertions: 114, Failures: 1` — the 1 failure is
only the new test). No collateral regression from the edit; the new test is additive.

## Ready for F
Confirmed RED is valid; F may implement against this test. F must:
1. Edit `ShowcaseCatalog.php:52` decision_sentence to name Map + the geographic/plotting axis
   (containing both "Map" and a "geograph…" word).
2. Edit `ShowcaseController.php:223` code comment (brief item 2 — not covered by this unit test,
   dev-facing comment only; verify manually/via phpcs).
