# Handoff-T-red: Phase 4 - #127 SD-2 Card ⓘ tooltips

**Date:** 2026-07-23
**Branch:** 127-card-tooltips
**Brief / wireframe reviewed:** `docs/planning/handoffs/127-card-tooltips/brief.md`, `docs/planning/handoffs/127-card-tooltips/survey.md` (D skipped, patterned append — no wireframe)

## A precondition
Confirmed: A returned **PASS** on the plan (Phase 3, `decisions.md`) — no blocks, two soft observations (preprocess-extension acceptable; recommend a `tooltips` sub-array naming, F's call).

## Tests authored

1. **`tests/e2e/element-tooltips.spec.ts`** (NEW) — 7 tests across 2 describe blocks:
   - `type, visibility, and member-count triggers carry the full tooltip contract` (directory card, e2e) — pins AC-1 + AC-6 + AC-7 (a11y).
   - `hovering a directory-card trigger shows a tippy tooltip` (e2e) — pins AC-1's "hovering... shows a sensible tooltip".
   - `visibility ⓘ copy is single-sourced from the reused visibility.* HelpText key` (e2e) — pins AC-4 (reuse, no duplicated string).
   - `no double-tooltip: card triggers are scoped inside .gc-card, not duplicated` (e2e) — pins AC-3.
   - `byline, content-type, and comments triggers carry the full tooltip contract` (stream card, e2e) — pins AC-2 + AC-6 + AC-7, and the "adjacent not nested" requirement for the comments footer (its anchor already owns an `aria-label`).
   - `hovering a stream-card trigger shows a tippy tooltip` (e2e) — pins AC-2's hover requirement.
   - `no double-tooltip: stream-card triggers are scoped inside .gc-card, not duplicated` (e2e) — pins AC-3.

   Tier: **e2e** — the DOM/attribute contract (tabindex, role, aria-label, data-do-tooltip) and the tippy hover behavior can only be observed against rendered markup + loaded JS; no cheaper tier (unit/kernel) can assert on twig output + client-side tippy init.

2. **`docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php`** (EXTENDED, +1 method) — `testCardTooltipCopyIsPresentAndPlainText`: pins AC-4/AC-6/AC-7's copy-source contract — the 5 new `card.*` keys exist as literal `HelpText::all()` entries, are non-empty plain text, and each names the vocabulary the brief specifies. Tier: **unit** — matches the file's own existing per-surface pattern (e.g. `testGroupTypeHomepageAdaptsCopyIsPresentAndNamesVariants`), no Drupal bootstrap needed. Does NOT duplicate the pre-existing `testVisibilityCopyIsPresentPlainTextAndHonest` (visibility reuse is already covered there).

## RED confirmation

**Unit** (assembled layout, inside DDEV `gm127-card-tooltips`):
```
ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php
```
```
Tests: 12, Assertions: 120, Failures: 1.
✘ Card tooltip copy is present and plain text
  "card.directory.type" must be a literal key in HelpText::all() (append-only contract).
  Failed asserting that an array has the key 'card.directory.type'.
```
Fails on the first missing key — the RIGHT reason (key not yet appended), not an import/setup/typo error. All 11 pre-existing tests remain green.

**E2E** (live, fully-seeded site — `drush site:install standard` → set matching UUID → `config:import` → enable do_* modules → seed `step_700/720/780/790` → served at `https://gm127-card-tooltips.ddev.site`):
```
BASE_URL="https://gm127-card-tooltips.ddev.site" npx playwright test tests/e2e/element-tooltips.spec.ts
```
```
7 failed
  ... type, visibility, and member-count triggers carry the full tooltip contract
  ... hovering a directory-card trigger shows a tippy tooltip
  ... visibility ⓘ copy is single-sourced from the reused visibility.* HelpText key
  ... no double-tooltip: card triggers are scoped inside .gc-card, not duplicated
  ... byline, content-type, and comments triggers carry the full tooltip contract
  ... hovering a stream-card trigger shows a tippy tooltip
  ... no double-tooltip: stream-card triggers are scoped inside .gc-card, not duplicated
```
Every failure is `toHaveCount`/`toBeVisible`/`toHaveAttribute` resolving to **0 elements** — `[data-do-tooltip]` does not exist anywhere inside `.gc-directory-card` / `.gc-stream-card` yet, since neither twig template nor either preprocess function has been touched. Never a navigation error, a missing route (both `/all-groups` and `/stream` return 200), or a selector typo.

**Baseline sanity check:** `tests/e2e/directory-cards.spec.ts` (pre-existing, adjacent surface, same seeded site) — 3/3 pass, confirming the RED is isolated to the new assertions and not an artifact of a broken environment.

## Environmental caveats for F
- Fresh worktree had no DDEV project; stood up `gm127-card-tooltips` (untracked `.ddev/config.local.yaml` override — the tracked `.ddev/config.yaml` still says `pl-groups-on-d11`, which is already running against the primary checkout). F should reuse this running project (`ddev start` in this worktree) rather than re-provisioning, unless it has been stopped.
- `web/sites/default/settings.php` (gitignored, not committed) has one added line pointing `config_sync_directory` at `../config/sync`, needed because DDEV's own default (`sites/default/files/sync`) does not match where `scripts/ci/assemble-config.sh` places config. This is local-only and mirrors the same override CI performs inline.
- Site was installed with the **`standard`** profile (matches `.github/workflows/test.yml`), not `minimal` — a first attempt with `minimal` produced a UUID/profile mismatch on `config:import` and had to be redone.

## Ready for F
**Confirmed RED is valid on both tiers — F may implement against these tests.**
