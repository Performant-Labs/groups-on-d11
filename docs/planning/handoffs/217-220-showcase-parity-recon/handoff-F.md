# Handoff-F: Phase 5 - Docs-parity reconciliation batch (#217, #218, #219, #220)

**Date:** 2026-07-24
**Branch:** 217-220-showcase-parity-recon
**Issue:** #217, #218, #219, #220 (all filed as part of #212 REL-3 docs-repo parity sweep)

## What was done

- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` —
  - **#217:** added a new `public-browse` catalog entry (first item in `entries()`, mirroring
    upstream feature-tour item #1's ordering), `status => 'live'`, `route =>
    'do_showcase.showcase'`, `upstream_ref` pointing at the upstream tour URL.
  - **#218:** rewrote the `group-type-homepages` entry's `decision_sentence` to name the three
    concrete variants (events-first / discussion-first / docs-first), matching upstream's framing
    and this repo's own already-aligned `HelpText.php:321` copy. Updated the adjacent `#212`
    inline comment to note the #218 reconciliation.
  - **#212 docblock housekeeping (companion to #217/#218):** dropped the stale "seven required
    catalog entries" wording from the `entries()` docblock (now eight, and the count is no longer
    hardcoded in prose) and added a class-level docblock paragraph summarizing all four
    reconciliation issues.
  - **#220:** extended `personas()` — `maria-chen`'s `description` now reads `'A group Organizer
    (called "Group Admin" in the upstream docs).'`; `moderator`'s `description` now reads `'A
    site-wide moderation role (called "Moderator" in the upstream docs).'`. Added a docblock
    paragraph on `personas()` explaining the deliberate cross-reference (why it lives in the
    user-visible `description` field, not just a source comment).
- `docs/groups/modules/do_chrome/src/HelpText.php` — **#217:** added
  `'showcase_help.public-browse'` key (7 lines: a docblock comment + the key/copy pair),
  positioned after the existing `showcase_help.*` block (right after `showcase_help.map`),
  following the file's own append-only convention.
- `docs/planning/decisions/0003-archive-semantics-no-catalog-entry.md` — **#219:** new decision
  record, modeled on the existing `0002-theme-toggle-rejected.md`. Documents that archive
  semantics ship (via moderation) but deliberately get no standalone `/showcase` catalog entry,
  because there is no demonstrable standalone comparison surface for it without violating the
  truthful-copy rule.
- `docs/planning/parity/showcase-vs-upstream.md` — updated both mapping tables, the personas
  table, and the reconciliation-issues section to reflect all four resolutions; added a "Resolved
  (2026-07-24)" section with one line per issue; added a `Recon date:` line to the header.

## Design decisions

1. **`public-browse` placed first in `entries()`**, matching upstream's own ordering (item #1 on
   the feature tour). This is purely a display-order choice — no test asserts array position (only
   `array_column`/`array_filter` lookups by `id`), so this doesn't risk breaking anything, and it
   keeps the local list's ordering legible against the upstream source of record for anyone
   diffing the two side by side.

2. **`public-browse` routes to `do_showcase.showcase`** (the tour page itself), same as
   `discovery-ranking` and `persona-switcher` — there's no dedicated "here's what anonymous
   browsing looks like" page; the tour page itself IS the anonymous-accessible surface being
   described. This matches the existing convention where an entry without a more specific
   demonstration target routes to the tour page.

3. **`showcase_help.public-browse` positioned at the end of the existing `showcase_help.*` block**
   (after `showcase_help.map`), not interleaved to match the new first-position ordering in
   `ShowcaseCatalog::entries()`. `HelpText.php`'s own docblock states the append-only contract
   explicitly ("Appended per the append-only HelpText contract") — every other addition to this
   file follows the pattern of appending at the end of its relevant block rather than reordering
   to match a data-array's display order. Consistency with that stated file-level convention was
   weighted higher than mirroring `ShowcaseCatalog`'s internal ordering.

4. **#219 resolved as a decision record, not a new catalog entry** (per the issue's own
   "Reconciliation path" option 2, and the task's explicit direction). Read the issue's two
   options carefully: option 1 (add an entry routed to a page that "kind of" demonstrates the
   distinction) would either mislead the visitor about what they'd see on arrival, or require
   building a new demo surface just to justify the entry — both out of scope and in tension with
   the post-#133 honesty-sweep invariant that every catalog entry is `live` with a real, truthful
   target. Option 2 (document the decision) doesn't require any code change and doesn't leave a
   dangling/misleading catalog entry.

5. **#220 resolved as an in-copy cross-reference in `description`, not a rename** (per the issue's
   own option 2, and the task's explicit direction). The persona `description` field is the
   user-visible surface a `/showcase` visitor actually reads (rendered via
   `ShowcaseController::build()`'s `@name — @description` line, confirmed by reading that
   controller before making this change) — putting the cross-reference there, rather than only in
   a docblock, means the drift is legible to a human comparing the two surfaces without having to
   go dig through source comments. The docblock note is additive documentation of *why*, not the
   only place the cross-reference lives.

6. **`decision_sentence` for `group-type-homepages` uses double-quoted `$this->t("...")`**, not
   single-quoted with a backslash-escaped apostrophe. The sentence needs an apostrophe ("the
   group's primary purpose") — a double-quoted PHP string needs no escaping for that character,
   which is both more readable and is `phpcs`'s (`DrupalPractice` sniff) stated preference
   ("Avoid backslash escaping in translatable strings when possible, use \"\" quotes instead").
   Caught and fixed during my own Tier-1 self-check (see below); does not change the translated
   string's content.

## Reuse / extend-vs-new

All four issues were explicitly framed by their own text as reconciliation-path choices on an
**existing** object (`ShowcaseCatalog::entries()`/`personas()`, `HelpText::all()`, the existing
`showcase-vs-upstream.md` parity report) or a **new object of an already-established kind**
(`0003-archive-semantics-no-catalog-entry.md`, following the exact precedent and template of the
existing `0002-theme-toggle-rejected.md` decision record — this repo already has a "decision
records" object category for exactly this situation). No new class, service, controller, route, or
parallel data source was created. `public-browse` is a new *entry* inside the existing catalog
array, not a new catalog — the correct level of "new" the brief called for.

## Architecture notes for A

- **Layers touched:** two PHP data-definition classes (`ShowcaseCatalog`, `HelpText` — both
  pure-data, no service dependencies beyond `StringTranslationTrait`), one new markdown decision
  record, one markdown parity report. No routes, schema, config, or Drupal service wiring changed.
- **No new dependencies.** Neither edited class gained a new `use` statement or constructor
  dependency.
- **Shared-file edit scope:** `HelpText.php` is a large, many-owner file (numerous prior stories'
  keys live in it). My diff is exactly 7 additive lines (one docblock comment + one key/copy
  pair), appended at the end of the relevant existing block — confirmed via `git diff` that no
  other line in the file was touched, and confirmed the 25 pre-existing `phpcs` findings in this
  file (comment-indentation, array-indentation, line-length issues on lines 21-447) are **all**
  outside my added-line range (333-339) — i.e., pre-existing debt, not introduced by this change,
  and out of scope to fix here per the "no drive-by refactor" rule.
- **`ShowcaseCatalog.php` is smaller and less shared** (fully owned by this feature area) — I did
  fix the two `phpcs` findings my own new lines introduced (a >80-char comment line, and the
  backslash-escape preference) rather than leaving new debt, since both were on lines I authored
  this phase, not pre-existing code.

## Deviations from spec / wireframe

None. No UI wireframe applies to this batch — it's a copy/data-shape reconciliation and one
markdown decision record, not a new UI surface. Every work-list item in the task brief was
implemented as specified (entry shape, field values, docblock content, decision-record structure,
parity-report edits) with only the two Tier-1 `phpcs` cleanups noted above as unrequested-but-
in-scope-hygiene additions on lines I authored.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble config** (ddev, per T's confirmed-working recipe):

```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 139 file(s), excluded 7 env-specific file(s)
==> modules: copied 16 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
==> assemble-config: done
```

**Target Unit test — `ShowcaseCatalogTest.php` — GREEN (all 17 tests, including the 4 RED-authored
for this batch: `testPublicBrowseEntryIsLive`, `testGroupTypeHomepagesEntryNamesThreeVariants`,
`testMariaChenDescriptionCrossReferencesUpstreamGroupAdmin`,
`testGroupsModeratePersonaDescriptionCrossReferencesUpstreamModerator`):**

```
$ ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
    php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php'

...................                                               19 / 19 (100%)

Showcase Catalog (Drupal\Tests\do_showcase\Unit\ShowcaseCatalog)
 ✔ All required entries are present
 ✔ Every entry has complete shape
 ✔ No entries are coming
 ✔ Live entries have a route
 ✔ Membership models entry is live
 ✔ Group type homepages and private group reveal are live
 ✔ Private group reveal entry references issue 134
 ✔ Persona switcher entry names all four personas
 ✔ Persona switcher entry is live
 ✔ Entry strings are translatable markup
 ✔ Maria chen persona description names organizer
 ✔ Stream model entry is live with activity stream route and corrected decision sentence
 ✔ Directory presentation entry names map variant
 ✔ Public browse entry is live
 ✔ Group type homepages entry names three variants
 ✔ Maria chen description cross references upstream group admin
 ✔ Groups moderate persona description cross references upstream moderator

OK (19 tests, 162 assertions)
```

**Target Kernel test — `HelpTextConsumerCoverageTest.php` — GREEN (2/2, confirming the new
`showcase_help.public-browse` key is properly whitelisted, no orphan regression):**

```
$ ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
    php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php'

Help Text Consumer Coverage (Drupal\Tests\do_chrome\Kernel\HelpTextConsumerCoverage)
 ✔ Chrome stream switcher has consumer
 ✔ Every help text key has a consumer

OK (2 tests, 36 assertions)
```

**Target Functional test — `ShowcaseControllerHelpTest.php` — GREEN (6/6, confirming
`entryIdsWithHelpCopy()`'s new `'public-browse'` entry resolves non-empty copy and renders its
help trigger):**

```
$ ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
    php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_showcase/tests/src/Functional/ShowcaseControllerHelpTest.php'

Showcase Controller Help (Drupal\Tests\do_showcase\Functional\ShowcaseControllerHelp)
 ✔ Every targeted entry id has non empty help copy
 ⚠ Each catalog entry with matching key renders help trigger
 ⚠ Help trigger is keyboard reachable with accessible attributes
 ⚠ Map orientation help trigger renders adjacent to switcher
 ⚠ Tooltips library is attached on showcase page
 ✔ Unknown entry id help key resolves empty guarding against empty tooltip render

Tests: 6, Assertions: 59, Deprecations: 7, PHPUnit Deprecations: 7.
```

(`⚠` = passed with a pre-existing framework deprecation notice triggered — NOT a failure; exit
code 0. The 7 deprecations are all the same pre-existing Drupal 11.3→12.0
`#[RunTestsInSeparateProcesses]` notice on this `BrowserTestBase` subclass, unrelated to this
change — T's test file, not mine to edit.)

**Non-regression check — `ShowcaseCatalogUpstreamRefTest.php` (pre-existing, asserts every entry
carries exactly one of `upstream_ref`/`local_only`) — GREEN, confirming the new `public-browse`
entry's shape doesn't violate this pre-existing invariant:**

```
✔ Every entry declares upstream ref or local only
✔ Upstream refs point at docs repo

OK (2 tests, 36 assertions)
```

**Non-regression check — full `do_showcase` Unit suite (62 tests) — GREEN:**

```
$ ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
    php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_showcase/tests/src/Unit'

..............................................................    62 / 62 (100%)
OK, but there were issues!
Tests: 62, Assertions: 383, PHPUnit Deprecations: 50.
```

**`php -l` syntax check on both edited production files — clean:**

```
$ ddev exec php -l web/modules/custom/do_showcase/src/ShowcaseCatalog.php
No syntax errors detected in web/modules/custom/do_showcase/src/ShowcaseCatalog.php
```

**`phpcs --standard=Drupal,DrupalPractice` on both edited production files:**

```
$ ddev exec php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
    web/modules/custom/do_showcase/src/ShowcaseCatalog.php \
    web/modules/custom/do_chrome/src/HelpText.php

FILE: .../do_chrome/src/HelpText.php
FOUND 18 ERRORS AND 9 WARNINGS AFFECTING 25 LINES
(ShowcaseCatalog.php not listed — 0 errors, 0 warnings)
```

`ShowcaseCatalog.php` is fully clean (0/0) after two self-fixes on my own added lines (an
82-character comment line rewrapped to fit 80; one `decision_sentence` converted from
single-quoted-with-backslash-escape to double-quoted, per the `DrupalPractice` sniff's stated
preference). Confirmed by line-number diff analysis that all 25 `HelpText.php` findings fall
outside my added-line range (333-339) — pre-existing debt, not introduced by this change, and out
of scope to fix (see Architecture notes above).

## Tests that look wrong (for T)

None. All RED tests for this batch (`testPublicBrowseEntryIsLive`,
`testGroupTypeHomepagesEntryNamesThreeVariants`,
`testMariaChenDescriptionCrossReferencesUpstreamGroupAdmin`,
`testGroupsModeratePersonaDescriptionCrossReferencesUpstreamModerator`, the widened
`entryIdsWithHelpCopy()` in `ShowcaseControllerHelpTest.php`, and the pre-whitelisted
`showcase_help.public-browse` entry in `HelpTextConsumerCoverageTest.php`) are correct as written
and went GREEN exactly as designed, with no test edits needed.

## Known issues

None outstanding. One self-caught-and-fixed process note, disclosed for transparency: an early
attempt to patch `HelpText.php` and `ShowcaseCatalog.php` via a Python byte-level string-replace
script hit two separate quoting/escaping false alarms in my own diagnostic tooling (a shell-arg
backslash-doubling artifact when constructing a search marker containing `\'`, and a Python
`unicode_escape`-encoding display artifact that made a single literal backslash in the file look
like two in a debug printout). Both were resolved by going to the raw byte level (`hex()`) and to
`ddev exec php -l` as ground truth before concluding anything was actually wrong — confirmed the
final file content was correct both times, and no bad state was ever written (the abandoned script
runs failed their own assertions before reaching any `write()` call, so the files on disk were
never corrupted mid-process). Mentioning this only so a reviewer isn't surprised by the two aborted
intermediate script attempts if they inspect shell history — the final diffs (shown under Files
changed below) are exactly the intended content, verified independently via `php -l`, `phpcs`, and
full test re-runs after every edit.

## Files changed

- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php`
- `docs/groups/modules/do_chrome/src/HelpText.php`
- `docs/planning/decisions/0003-archive-semantics-no-catalog-entry.md` (new file)
- `docs/planning/parity/showcase-vs-upstream.md`

(No test files touched — `ShowcaseCatalogTest.php`, `ShowcaseControllerHelpTest.php`, and
`HelpTextConsumerCoverageTest.php` are T's files, unmodified by this handoff. All four production
files above are staged by explicit path; T's three test files remain staged as T left them.)
