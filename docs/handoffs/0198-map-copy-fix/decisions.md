# Decision journal — #198 Docs parity: map-copy fix

## O (Phase 1 — brief)
**Decided:** POC lean pipeline (O → A → T-RED → F → T-GREEN → U → S → PR). Skip D
(no UI surface change), skip A-dup + brief-gate + pre-PR-hold per standing POC rule.

**Decided:** Two stale references identified from survey:
- `ShowcaseCatalog.php:52` — decision_sentence omits Map (USER-VISIBLE).
- `ShowcaseController.php:223` — code comment says "Map unavailable" (drift).

**Decided:** Extend existing `ShowcaseCatalogTest` with one regression assertion — the
analogous #202 pattern (orphan-copy regression sweep) matches this fix's shape 1:1. No new
test class.

**Assumed:** `ShowcaseCatalogTest` already exists and covers the entries() shape (survey
confirmed via grep: `ShowcaseCatalogTest.php:270` asserts stream-model status).

## A (Phase 3 — up-front plan review)
**Verdict:** PASS. Plan aligns with existing patterns; no blocks, no warns.

**Confirmed:** Test locus (`ShowcaseCatalogTest`) matches the file's established cadence —
`testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence` (L265-276) is a
1:1 template for the new per-entry `decision_sentence` substring assertion.

**Confirmed:** No duplication — no existing assertion pins the directory-presentation
`decision_sentence` string content (only status/route in `ShowcaseCatalogDirectoryLiveTest`).

**Confirmed:** No missed stale references — grep across `docs/groups/` (source, templates,
config, HelpText, tests) shows the stale phrase lives ONLY in the two files the brief names.
`HelpText.php:169` already carries the target voice (all three variants named).

**Confirmed:** No pinned-OLD-phrase trap — grep for `list vs. card layouts` matches only the
source file; safe to rewrite. Unlike #133/#202, the original decision_sentence was never
test-pinned.

**Report:** `handoff-A-plan.md`.

## T (Phase 4 — RED)
**Decided:** Added `testDirectoryPresentationEntryNamesMapVariant` to `ShowcaseCatalogTest.php`
(lines 278-311), 1:1 modeled on `testStreamModelEntryIsLive…` (L265-276). Locates the
`directory-presentation` entry, casts `decision_sentence` to string, asserts it contains both
"Map" and "geograph".

**Decided (keyword choice):** `geograph` over `location`/`plot` — it's the exact root
HelpText.php:169 already uses for this variant ("Map plots groups geographically"), so pinning
it keeps the two copy surfaces from drifting into different vocabularies for the same concept.
`Map` is asserted separately (variant name) from `geograph` (the axis it introduces) so the fix
must supply both, not just one.

**Evidence:** RED confirmed —
`ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php --filter testDirectoryPresentationEntryNamesMapVariant`
fails with `Failed asserting that 'Compares list vs. card layouts for the group directory — the
decision: information density vs. visual scannability.' … contains "Map"` — the exact stale
string brief.md cites, confirming a valid RED (feature assertion fails, not a setup/import
error). Full-suite `--testdox` run confirms all 12 pre-existing tests in the file remain green
(`Tests: 13, Assertions: 114, Failures: 1`) — no collateral regression.

**Assumed:** DDEV container namespace `gm198-mapcopy` needed a fresh `ddev start` — the worktree's
`.ddev/config.yaml` carried a stale `name: gm145-wcag` copied from another worktree checkout;
renamed to `gm198-mapcopy` before starting (worktree-local `.ddev/config.yaml` change, not
committed as part of this test change).

**Report:** `handoff-T-red.md`.

## F (Phase 5 — implementation)
**Decided:** `ShowcaseCatalog.php:52` decision_sentence rewritten to:
"Compares list, cards, and a Map that plots groups geographically for the group directory —
the decision: information density vs. visual scannability vs. geographic browsing." Contains
both "Map" and "geograph" (via "geographically"), satisfying T's RED assertion. Kept the
"Compares … — the decision: …" sentence shape used by neighboring entries (e.g. line 45,
discovery-ranking) for voice consistency; extended the trailing decision clause with a third
term ("geographic browsing") to match the third variant added, rather than leaving a two-way
tradeoff description for a three-way comparison.

**Decided:** `ShowcaseController.php:223` — deleted the ", Map unavailable" clause only,
leaving "(wireframe.md's own example: Compact list / Cards / Map)." Rest of the comment
(shared-options provenance, #124 SC-5 / A-advisory #7 references) left intact per the brief's
scope.

**Decided (verification):** Ran `php vendor/bin/phpcs` with `--standard=Drupal` explicitly.
The bare default invocation (no `--standard` flag) falls back to PEAR-style rules (2-space
indent, mandatory `@package`/`@author`/`@license` doc tags) that flag ~55 pre-existing,
unrelated violations across the entire file — none on the two lines this story touches, and
this project has no committed `phpcs.xml` ruleset or CI phpcs step to imply a different
default. `Drupal` and `DrupalPractice` are installed standards and this is a Drupal custom
module, so `--standard=Drupal` is the applicable standard; it reports zero errors/warnings
(exit 0) on both touched files.

**Evidence:** GREEN confirmed —
`ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php`
→ `Tests: 13, Assertions: 115, PHPUnit Deprecations: 14` — all 13 pass, including
`testDirectoryPresentationEntryNamesMapVariant`. `phpcs --standard=Drupal` on both touched
files → exit 0, no output. `git diff` confirms only the two intended one-line hunks changed;
no test file, no VariantSwitcher/HelpText file touched.

**Report:** `handoff-F.md`.

## T (Phase 6 — GREEN)
**Decided:** Ran an independent verification beyond F's single-file check: assembled fresh via
`ddev exec bash scripts/ci/assemble-config.sh` (direct bash invocation failed — `php` not on
PATH outside the container; not a project defect), re-ran the target `ShowcaseCatalogTest.php`
(13/13 GREEN, matches F), then swept the full do_showcase Kernel + Unit suite (86 tests) to
check for drift.

**Decided:** The bare Kernel-test invocation from the task instructions errors all Kernel tests
("no database connection") — required `SIMPLETEST_DB='mysql://db:db@db:3306/db'` (ddev's db
service; CI's `test.yml` uses an analogous `mysql://root:root@mysql:3306/drupal` for its service
container). Not a regression — a pre-existing local-runner requirement this repo doesn't
document. Flagged as an advisory note, not a blocker.

**Confirmed:** Grep-guard's one hit (`ShowcaseCatalogTest.php:298`) is the test's own docblock
documenting the pre-fix string as RED-time history, not stale production copy — source and
controller comment are clean. Spot-checked the assembled source directly
(`ShowcaseCatalog.php:52`) to confirm the new decision_sentence string is present and matches
what the test asserts, proving the test pins real behavior, not a tautology.

**Evidence:** Target suite 13/13, wider sweep 86/86 (`Tests: 86, Assertions: 726, Deprecations:
6` — zero Failures/Errors), phpcs `--standard=Drupal` exit 0 on both touched files, grep-guard
zero stale-copy matches in shipped code.

**Report:** `handoff-T-green.md`.

## S (Phase 9 — spec audit)
**Verdict:** PASS. Ready for PR.

**Confirmed (Q1 — acceptance criteria):** All 6 criteria satisfied. Source lines
verified in place (`ShowcaseCatalog.php:52`, `ShowcaseController.php:223`); test pins
behavior not implementation; wider sweep 86/86; phpcs clean; grep-guard clean in
shipped code.

**Confirmed (Q2 — issue #198's ask):** The fourth path (update copy to name now-live
Map) is defensibly the correct reconciliation. #198 was filed before #125 SC-6 shipped
Map; the stale copy was stale in the OPPOSITE direction the issue assumed. Naming Map
matches shipped reality and satisfies the issue's underlying intent (copy must match
the UI's truthfulness contract).

**Confirmed (Q3 — drift):** Independent grep of `docs/groups/` for stale fragments
returns only test-fixture and test-docblock hits (VariantSwitcherTest fallback-behavior
fixture semantics; ShowcaseCatalogTest docblock history; DirectoryTogglePreRenderTest
correctly asserts "Map (soon)" absent). No stale production copy remains.

**Confirmed (Q4 — reviewer surprises):** New decision_sentence mirrors neighboring
entry shape (`discovery-ranking` L45); controller comment trims cleanly with no orphan
punctuation. No tone drift, no broken sentence.

**Confirmed (U-skip):** Defensible-N/A. Two-word copy edit + code-comment trim, unit
test pins exact substrings ("Map"+"geograph"), grep-guard confirms no stale phrase in
shipped code. A U cycle would produce zero new signal. POC lean pipeline correctly
applied.

**Advisory:** T-green's `SIMPLETEST_DB` local-runner note worth folding into
RUNBOOK.md/TEST_PLAN.md at some point — not a blocker.
