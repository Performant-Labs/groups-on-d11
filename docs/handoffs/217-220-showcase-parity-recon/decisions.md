# Decision journal — #217-220 batched docs-parity reconciliation

Append-only. One entry per phase. Format per `pipeline-conventions.md`.

---

## Phase 4 — T (Tester, RED)

**Decided:**
- Extended 3 existing test files, created no new test classes, per A's plan and the task instruction:
  `ShowcaseCatalogTest.php` (Unit — renamed/updated 1 test, added 4 new tests), `ShowcaseControllerHelpTest.php`
  (Functional — 1-line array extension + docblock), `HelpTextConsumerCoverageTest.php` (Kernel — whitelist
  addition per A's Finding #1).
- `testAllSevenRequiredEntriesArePresent` → renamed `testAllRequiredEntriesArePresent`, count 7→8, ids
  array gains `public-browse`, de-numbered messages (A's Finding #2, T's half).
- `testMariaChenPersonaDescriptionNamesOrganizerOnly` → renamed `testMariaChenPersonaDescriptionNamesOrganizer`,
  assertion loosened `assertSame` → `assertStringContainsString('Organizer', ...)`. This is a legitimate test
  repair: the exact-match pinned a stricter invariant (the full string) than the property that matters (the
  #133 honesty-sweep intent — name "Organizer", not the stale "admin/organizer" hedge). #220's additive
  cross-reference text is a separate NEW test (`testMariaChenDescriptionCrossReferencesUpstreamGroupAdmin`),
  so the original test's core invariant survives unweakened while allowing the field to grow.
- 4 new RED tests: `testPublicBrowseEntryIsLive` (#217), `testGroupTypeHomepagesEntryNamesThreeVariants`
  (#218), `testMariaChenDescriptionCrossReferencesUpstreamGroupAdmin` + `testGroupsModeratePersonaDescription
  CrossReferencesUpstreamModerator` (#220, one per persona).
- #219 (Archive Semantics): no test authored. Path 2 is a docs-only decision record
  (`docs/planning/decisions/0003-archive-semantics-no-catalog-entry.md`), analog to `0002-theme-toggle-
  rejected.md`. Judged a trivial doc-existence test not worth the file churn — the record's content isn't
  meaningfully test-checkable beyond "the file exists," and F/O can confirm presence directly.
- Fixed a broken worktree DDEV setup (stale `name: gm145-wcag` in `.ddev/config.yaml` plus a stray
  `.ddev/config.gm139.yaml` override forcing `name: gm139-multilang-rtl`) — both were leftover copy-paste
  debt colliding with unrelated already-running DDEV projects on this machine, blocking `ddev start`
  entirely. Renamed to `gm217-parity`, deleted the stray override file. Not a test-file change; recorded
  here because it was necessary to get RED confirmed at all.

**RED confirmed by actual execution** (not analytical tracing) — `ddev start` + `ddev composer install` +
`bash scripts/ci/assemble-config.sh` all succeeded after the DDEV fix; ran the full Unit suite (5 failures,
all for the right reason, 12 pre-existing tests pass unchanged) and independently confirmed the Kernel
whitelist addition is inert (2/2 pass) and the Functional precondition test fails on the missing
`showcase_help.public-browse` HelpText key.

**Assumed:** None beyond what the task brief specified.

**Hedged:** None — full live PHPUnit execution against the assembled layout, per the pipeline's explicit
preference over analytical tracing.

**Evidence:** `docs/handoffs/217-220-showcase-parity-recon/handoff-T-red.md`; live `ddev exec php
vendor/bin/phpunit` output for all three test files; `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php`
(pre-F state, confirming no `public-browse` entry, abstract `group-type-homepages` decision_sentence, and
the pre-#220 persona descriptions); `docs/groups/modules/do_chrome/src/HelpText.php` (confirming no
`showcase_help.public-browse` key exists yet).

**Verdict: RED confirmed valid. F may implement against these tests.**
