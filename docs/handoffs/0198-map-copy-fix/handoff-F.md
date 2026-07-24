# Handoff-F: Phase 5 - #198 Docs parity: directory-layout copy vs. shipped Map view

**Date:** 2026-07-24
**Branch:** 198-map-copy-fix
**Issue:** #198

## What was done
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` — rewrote the `directory-presentation`
  entry's `decision_sentence` (line 52) to name all three variants (list, cards, Map) and the
  geographic axis Map introduces.
- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` — deleted the stale
  ", Map unavailable" clause from the code comment at line 223 (Map shipped live in #125 SC-6).

## Design decisions
- **`decision_sentence` rewrite** — new text: "Compares list, cards, and a Map that plots groups
  geographically for the group directory — the decision: information density vs. visual
  scannability vs. geographic browsing." Kept the "Compares … — the decision: …" shape used by
  neighboring catalog entries (e.g. line 45, discovery-ranking) rather than restructuring the
  sentence. Extended the trailing decision clause to a three-way tradeoff ("… vs. geographic
  browsing") since a third variant was added, rather than leaving a two-way framing that no
  longer matches three compared options.
- **Comment trim, not rewrite** — deleted only the false clause (", Map unavailable)"), left the
  rest of the comment (wireframe.md provenance, shared-options cross-reference to #124 SC-5 /
  A-advisory #7) untouched, per brief scope ("Leave the rest of the comment intact").
- **phpcs standard selection** — the bare `php vendor/bin/phpcs <file>` invocation falls back to
  a PEAR-style default (this project has no committed `phpcs.xml` ruleset and no phpcs step in
  CI), which flags ~55 pre-existing, unrelated violations across the whole file (2-space indent,
  missing `@package`/`@author` doc tags) — none on the two edited lines. Re-ran with
  `--standard=Drupal` (installed, and the applicable standard for a Drupal custom module): clean,
  exit 0, on both files.

## Reuse / extend-vs-new
Extended both files named in the brief's Reuse map (`ShowcaseCatalog.php`, `ShowcaseController.php`)
— no new file, no new class, no new module. Matches the brief's cited analogous prior repair
(#202, ba564ec) exactly: a copy string drifted from shipped reality, was corrected in place, with
a regression assertion (T's, not mine) pinning the fix.

## Architecture notes for A
None — pure string edits, no layer/dependency/schema/contract change. `ShowcaseCatalog` remains a
pure data class with no service deps; `ShowcaseController`'s comment edit has no runtime effect.

## Deviations from spec / wireframe
None. Both edits match the brief's example shape and stayed within its stated scope (no touch to
VariantSwitcher, HelpText, or the test file).

## Tier 1 self-check (incl. tests now GREEN)

Assemble:
```
ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: done
```

PHPUnit (full `ShowcaseCatalogTest`, matching T's invocation):
```
ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php
...
 ✔ Directory presentation entry names map variant
OK, but there were issues!
Tests: 13, Assertions: 115, PHPUnit Deprecations: 14.
```
All 13/13 pass, including the previously-RED `testDirectoryPresentationEntryNamesMapVariant`.

phpcs (`--standard=Drupal`, applicable standard — see Design decisions above):
```
ddev exec php vendor/bin/phpcs --standard=Drupal web/modules/custom/do_showcase/src/ShowcaseCatalog.php web/modules/custom/do_showcase/src/Controller/ShowcaseController.php
(no output — exit 0)
```

## Tests that look wrong (for T)
None. `testDirectoryPresentationEntryNamesMapVariant` is correct and passes unmodified against
the implementation.

## Known issues
None. All acceptance criteria for the F-owned edits are met.

## Files changed
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php`
- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php`
