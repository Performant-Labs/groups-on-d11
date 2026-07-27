# Handoff-T-red: Phase 4 - Batched docs-parity reconciliation (#217, #218, #219, #220)

**Date:** 2026-07-24
**Branch:** `217-220-showcase-parity-recon`
**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-showcase-parity-recon-217-220`
**Brief / wireframe reviewed:** Issues #217, #218, #219, #220 (verbatim, no separate brief.md); no wireframe (no new UI surface — copy/data-only changes to an existing catalog).

## A precondition

Confirmed: A returned PASS on the plan (Phase 3) with 2 warn findings, both folded into this test-authoring pass:
- Finding #1 (Kernel whitelist gap for `showcase_help.public-browse`) — addressed below.
- Finding #2 ("seven"/"eight" docblock language in `ShowcaseCatalogTest.php`) — addressed below (F owns the `ShowcaseCatalog.php` side).

## Tests authored

All work extends existing test files per A's finding — **no new test classes created**, per instructions.

### `docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php` (Unit tier — pure PHP-array data, no Drupal services)

1. **Renamed** `testAllSevenRequiredEntriesArePresent` → `testAllRequiredEntriesArePresent`. Assertion count bumped 7→8, expected-ids array now includes `'public-browse'`, messages de-numbered ("All required comparison/persona entries must be present by id." / "Every required catalog entry is present."). Pins: the catalog's entry-count/id-set contract (#217 changes the count).

2. **New `testPublicBrowseEntryIsLive`** — pins #217's acceptance criterion: a `public-browse` entry must exist, be `live`, carry a non-null `route`, and carry a non-empty `upstream_ref`. Unit tier — pure data assertion on `ShowcaseCatalog::entries()`, cheapest sufficient tier.

3. **New `testGroupTypeHomepagesEntryNamesThreeVariants`** — pins #218's acceptance criterion: the `group-type-homepages` entry's `decision_sentence` must name all three concrete variants (`events-first`, `discussion-first`, `docs-first`), mirroring `HelpText.php:321`'s existing wording. Unit tier.

4. **New `testMariaChenDescriptionCrossReferencesUpstreamGroupAdmin`** (#220 part 1) — pins that `personaSpec('maria-chen')['description']` names the upstream "Group Admin" role. Unit tier.

5. **New `testGroupsModeratePersonaDescriptionCrossReferencesUpstreamModerator`** (#220 part 2) — pins that `personaSpec('moderator')['description']` names the upstream "Moderator" role. Unit tier.

6. **Updated** `testMariaChenPersonaDescriptionNamesOrganizerOnly` → renamed `testMariaChenPersonaDescriptionNamesOrganizer`, assertion loosened from `assertSame('A group Organizer.', ...)` to `assertStringContainsString('Organizer', ...)`. This is a legitimate test repair, not a weakening of intent: the exact-match assertion pinned a stricter invariant (the full string) than the property that matters (the #133 honesty-sweep intent — name "Organizer", not the stale "admin/organizer" hedge). #220's cross-reference text is additive to the same field, so the exact-match would break for a reason unrelated to what the test is supposed to pin. Docblock updated to explain the loosening.

### `docs/groups/modules/do_showcase/tests/src/Functional/ShowcaseControllerHelpTest.php` (Functional tier — real HTTP response, real rendered markup)

- Added `'public-browse'` to `entryIdsWithHelpCopy()` (drives both the file's own precondition test `testEveryTargetedEntryIdHasNonEmptyHelpCopy` and its render-assertion loop). Updated docblock (removed "seven", now "all of the current ... ids").

### `docs/groups/modules/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php` (Kernel tier — A's Finding #1)

- Added `'showcase_help.public-browse'` to `whitelistedKeys()`, extended the enumerating comment to name `'public-browse'` and cite #217. This is a **coverage-integrity fix**, not new RED: without it, once F adds the `showcase_help.public-browse` HelpText key, `testEveryHelpTextKeyHasAConsumer` would start flagging a false-positive "unconsumed key" (the whitelist mechanism specifically exists to avoid that). It is inert (doesn't change pass/fail) until F adds the key — confirmed both Kernel tests pass now, and will continue to pass once F's change lands, precisely because the whitelist was updated in step.

## RED confirmation

Run command (from the assembled layout, after `bash scripts/ci/assemble-config.sh`):

```
ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php
```

Output (5 failures, all for the right reason — the new/changed assertions, not import/setup errors):

```
F............FFFF                                                 17 / 17 (100%)

 ✘ All required entries are present
   │ Every required catalog entry is present.
   │ Failed asserting that actual size 7 matches expected size 8.

 ✔ [12 pre-existing tests pass unchanged]

 ✘ Public browse entry is live
   │ A public-browse catalog entry must exist (#217 REL-3 parity — upstream feature-tour item #1, "Anonymous read access").
   │ Failed asserting that false is not false.

 ✘ Group type homepages entry names three variants
   │ group-type-homepages decision_sentence must name the "events-first" variant (#218 REL-3 parity, mirrors HelpText.php:321).
   │ Failed asserting that 'Compares a generic group page vs. a type-tailored homepage — the decision: general-purpose UI vs. per-type customization.' [UTF-8](length: 123) contains "events-first" [ASCII](length: 12).

 ✘ Maria chen description cross references upstream group admin
   │ maria-chen's persona description must cross-reference the upstream 'Group Admin' role name (#220 REL-3 parity, persona-name drift).
   │ Failed asserting that 'A group Organizer.' [ASCII](length: 18) contains "Group Admin" [ASCII](length: 11).

 ✘ Groups moderate persona description cross references upstream moderator
   │ The moderator persona description must cross-reference the upstream "Moderator" role name (#220 REL-3 parity, persona-name drift).
   │ Failed asserting that 'A site-wide moderation role.' [ASCII](length: 28) contains "Moderator" [ASCII](length: 9).

Tests: 17, Assertions: 121, Failures: 5, PHPUnit Deprecations: 18.
```

Note the renamed `testMariaChenPersonaDescriptionNamesOrganizer` **passes** already (correct — it's a test repair pinning an already-true invariant, not new RED for #220; the NEW cross-reference assertion is the separate `testMariaChenDescriptionCrossReferencesUpstreamGroupAdmin` test, which does fail).

Kernel whitelist test (confirms A's Finding #1 fix is inert/non-breaking at RED time — required `SIMPLETEST_DB` env var, not present by default in this DDEV image):

```
ddev exec bash -c "SIMPLETEST_DB='mysql://db:db@db/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php"

 ✔ Chrome stream switcher has consumer
 ✔ Every help text key has a consumer

OK (2 tests, 36 assertions)
```

Functional precondition test (confirms RED independently at the Functional tier — proves the render-assertion loop in this file will also fail until F adds the HelpText key):

```
ddev exec bash -c "SIMPLETEST_DB='mysql://db:db@db/db' SIMPLETEST_BASE_URL='http://gm217-parity.ddev.site' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Functional/ShowcaseControllerHelpTest.php --filter testEveryTargetedEntryIdHasNonEmptyHelpCopy"

 ✘ Every targeted entry id has non empty help copy
   │ showcase_help.public-browse must resolve to non-empty copy (precondition for this test file's render assertions).
   │ Failed asserting that two strings are not identical.

Tests: 1, Assertions: 9, Failures: 1.
```

(7 deprecation notices in that run are pre-existing framework/geofield noise, unrelated to this change — confirmed by their file paths all pointing into `web/core`/`vendor`/`geofield`, none into do_showcase/do_chrome.)

## #219 (Archive Semantics)

No test coverage authored, per the plan — Path 2 (decision record `docs/planning/decisions/0003-archive-semantics-no-catalog-entry.md`, analog to `0002-theme-toggle-rejected.md`) is docs-only. Judged a trivial doc-existence test not worth the additional test-file churn given the record does not yet exist and its content isn't test-checkable in any meaningful way beyond "the file exists" — F/O can confirm its presence directly at handoff review. No RED needed for this issue.

## Environment note (worktree DDEV project collision — fixed)

The worktree's `.ddev/config.yaml` had `name: gm145-wcag` (a stale copy-paste from another worktree) plus a stray `.ddev/config.gm139.yaml` override forcing `name: gm139-multilang-rtl` — both collided with unrelated already-running DDEV projects and blocked `ddev start`. Renamed this worktree's project to `gm217-parity` and deleted the stray `config.gm139.yaml` override file (pre-existing worktree setup debt, unrelated to any test file). `ddev start` + `ddev composer install` + `bash scripts/ci/assemble-config.sh` now succeed cleanly from this worktree.

## Ready for F

Confirmed RED is valid — 5 new/modified Unit-tier assertions fail for the right reason (the feature/copy the issue asks for is absent), the Functional-tier precondition independently confirms the same gap, and the Kernel whitelist addition is verified inert (both tests green) so it won't falsely block F's later `showcase_help.public-browse` addition. F may implement against these tests.
