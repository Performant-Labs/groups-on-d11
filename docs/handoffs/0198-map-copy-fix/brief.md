# Brief — #198 Docs parity: directory-layout copy vs. shipped Map view

## Objective
Fix stale copy that describes the `directory.layout` variant set as list-vs-cards only,
omitting the Map option that #125 SC-6 shipped as live. Add a small regression guard
that prevents this class of drift for the `directory-presentation` catalog entry.

## Background (survey)
The issue (#198) was filed BEFORE #125 SC-6 (Directory map view) shipped. Since then:

- `VariantSwitcher::directoryLayoutOptionIds()` (VariantSwitcher.php:87-93) already has
  `map` as a live entry (no `available => FALSE`).
- `HelpText.php:169` — switcher.directory.layout tooltip correctly names all three
  ("Compact list… Cards… Map plots groups geographically.").
- `HelpText.php:332` — `showcase_help.map` orientation copy correct.
- Kernel test `DirectoryTogglePreRenderTest::testMapOptionNoLongerCarriesUnavailableMarker`
  (line 265) already asserts `Map (soon)` is absent post-#125.

**Two stale references remain** — both drifted from the shipped reality:

1. **`ShowcaseCatalog.php:52`** (USER-VISIBLE on `/showcase`):
   ```
   'decision_sentence' => $this->t('Compares list vs. card layouts for the group
     directory — the decision: information density vs. visual scannability.'),
   ```
   Should name Map as the third variant and the geo/plotting axis it introduces.

2. **`ShowcaseController.php:223`** (code comment, dev-facing):
   ```
   // The one guaranteed wired stub switcher instance (wireframe.md's own
   // example: Compact list / Cards / Map, Map unavailable). Options are
   ```
   "Map unavailable" is factually wrong post-#125.

## Reuse & Analogous-Feature map
This is a copy fix, not a feature. The **analogous prior repair** is #202 (`chrome.stream_switcher`
consumer wire + orphan-copy regression sweep, ba564ec) — same pattern: a copy string in
`ShowcaseCatalog` drifted from what shipped, was updated, and a regression assertion was added
against the specific stale phrase. Extend that pattern rather than inventing anything new:

- **Extend `ShowcaseCatalog.php`** — edit line 52 decision_sentence to name Map.
- **Extend `ShowcaseController.php`** — edit line 223 code comment (or drop the
  "Map unavailable" clause).
- **Extend `ShowcaseCatalogTest.php`** (existing Unit test) — add ONE regression assertion
  that the directory-presentation decision_sentence contains "Map" (or equivalent
  geo/plotting keyword). Grep-style, not a full render assertion.

**Default: extend, do not add new files.** No new module, no new test class, no new twig,
no new hook.

## Acceptance criteria
- [ ] `ShowcaseCatalog.php:52` decision_sentence names Map as the third variant.
- [ ] `ShowcaseController.php:223` code comment no longer says "Map unavailable".
- [ ] Existing `ShowcaseCatalogTest` gains ONE assertion: directory-presentation entry's
      decision_sentence mentions Map (or "geograph"/"location"). Assertion FAILS on unchanged
      source (RED), PASSES after F's edit (GREEN).
- [ ] `php vendor/bin/phpcs` clean on touched files.
- [ ] No other test regresses (existing directory/showcase suites still green).
- [ ] U walkthrough: browse `/showcase`, confirm the directory-presentation catalog entry's
      copy names Map and reads coherently.

## Handoff locations
`docs/handoffs/0198-map-copy-fix/` — decisions.md, handoff-A-plan.md, handoff-T-red.md,
handoff-F.md, handoff-T-green.md, handoff-U.md, handoff-S.md.

## Review-rigor dial
`none` — this is a two-line copy fix with a one-line regression assertion. No brief-gate,
no diff-gate. POC lean pipeline: O → A → T(RED) → F → T(GREEN) → U → S → PR.

## Branch
`198-map-copy-fix` (worktree `~/Projects/_worktrees/groups-map-copy-198`, base 5a8d188).
