# Decisions log: Docs-parity reconciliation batch (#217, #218, #219, #220)

## Phase 5 (F-green) — 2026-07-24

**Decided:**
- **#217:** added a new `public-browse` entry to `ShowcaseCatalog::entries()`, placed first
  (mirroring upstream's own item #1 ordering), `status => 'live'`, `route =>
  'do_showcase.showcase'` (no more specific demonstration target exists — the tour page itself is
  the anonymous-accessible surface being described), `upstream_ref` set to the shared upstream
  tour URL. Added the matching `showcase_help.public-browse` key to `HelpText.php`, appended at the
  end of the existing `showcase_help.*` block per that file's own stated append-only convention
  (not reordered to mirror the catalog's new first-position ordering).
- **#218:** rewrote `group-type-homepages`'s `decision_sentence` to name the three concrete
  variants (events-first / discussion-first / docs-first), matching upstream's framing and this
  repo's own already-aligned `HelpText.php:321` copy — closing the one remaining abstract-copy
  drift the #212 sweep found.
- **#219:** resolved via a new decision record
  (`docs/planning/decisions/0003-archive-semantics-no-catalog-entry.md`), NOT a new catalog entry
  — per the issue's own option 2 and the task's explicit direction. No code change.
- **#220:** resolved via an in-copy cross-reference in the `description` field (the user-visible
  surface a `/showcase` visitor actually reads), NOT a rename — per the issue's own option 2 and
  the task's explicit direction. `maria-chen` and `moderator` personas' `description` fields now
  each name their corresponding upstream role.
- `ShowcaseCatalog::entries()`'s docblock updated to drop the stale "seven required" count wording
  (now eight entries; the docblock no longer hardcodes a count in prose).
- Two `phpcs` findings on my own newly-authored lines in `ShowcaseCatalog.php` (an 82-char comment
  line; one backslash-escaped-apostrophe string) were fixed during Tier-1 self-check — both are on
  lines I authored this phase, not pre-existing debt, so fixing them is in scope (unlike the 25
  pre-existing `phpcs` findings in `HelpText.php`, which are outside my added-line range and were
  left untouched per the no-drive-by-refactor rule).

**Assumed:**
- None beyond what the task brief already fully specified — every field value, docblock wording
  suggestion, and file location was explicit in the task instructions and cross-verified directly
  against the actual source (`ShowcaseCatalog.php`, `HelpText.php`,
  `ShowcaseCatalogUpstreamRefTest.php`, `HelpTextConsumerCoverageTest.php`'s `whitelistedKeys()`)
  before writing any code.

**Hedged:**
- None on implementation correctness — every claim below is verified by actually running the
  commands, not inferred. Process note only: two intermediate Python patch-script attempts
  produced false "the file might be corrupted" alarms in my own diagnostic tooling (a shell
  backslash-escaping artifact constructing a search marker, and a `unicode_escape`-encoding
  display artifact in a debug printout) — both resolved by checking raw bytes (`hex()`) and
  `ddev exec php -l` as ground truth. No bad state was ever written to disk; the aborted script
  runs failed their own internal assertions before reaching any file-write call. See
  handoff-F.md's "Known issues" section for the full account.

**Evidence:**
- `ShowcaseCatalogTest.php`: 17/17 GREEN (`OK (19 tests, 162 assertions)` combined with the 2
  pre-existing `ShowcaseCatalogUpstreamRefTest.php` non-regression tests run in the same
  invocation), including all 4 new-for-this-batch tests
  (`testPublicBrowseEntryIsLive`, `testGroupTypeHomepagesEntryNamesThreeVariants`,
  `testMariaChenDescriptionCrossReferencesUpstreamGroupAdmin`,
  `testGroupsModeratePersonaDescriptionCrossReferencesUpstreamModerator`).
- `HelpTextConsumerCoverageTest.php`: 2/2 GREEN (`OK (2 tests, 36 assertions)`) — confirms
  `showcase_help.public-browse` doesn't orphan-fail the regression sweep (it's covered by the
  test's own pre-existing two-part-literal-concatenation whitelist entry).
- `ShowcaseControllerHelpTest.php`: 6/6 GREEN (`Tests: 6, Assertions: 59`) — confirms
  `entryIdsWithHelpCopy()`'s widened list (now including `'public-browse'`) resolves non-empty
  copy and the entry renders its help trigger correctly. The 7 deprecation notices triggered are
  all the same pre-existing Drupal 11.3→12.0 `#[RunTestsInSeparateProcesses]` notice on this test
  class, unrelated to this change (T's file, unmodified).
- Full `do_showcase` Unit suite (62 tests, not required by the task, run as a non-regression
  sanity check): all 62 GREEN (`Tests: 62, Assertions: 383`).
- `php -l` on both edited production files: clean, no syntax errors.
- `phpcs --standard=Drupal,DrupalPractice`: `ShowcaseCatalog.php` fully clean (0 errors, 0
  warnings) after 2 self-fixes on newly-authored lines. `HelpText.php` carries 18 errors / 9
  warnings across 25 lines — verified by direct line-number comparison against my 7-line diff
  (lines 333-339) that every one of the 25 flagged lines falls outside my added range; all
  pre-existing, not introduced by this change.
- Full RED-to-GREEN reproduction commands and complete test output are in handoff-F.md in this
  directory.
