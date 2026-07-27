# Decision journal — #194 profile_activity.section consumer

## O — Phase 1 (brief)
- **Decided:** Wire via `preprocessBlock()` (extend existing 8-line method) rather than new twig override or new hook. Attribute on the block's outer wrapper — mirrors `.do-chrome-perm-matrix__intro[data-do-tooltip]` precedent.
- **Decided:** Add `do_chrome:do_chrome` dependency to `do_streams.info.yml`. Sibling #193 will need the same line — resolve at rebase by union.
- **Decided:** Skip D (no new UI element — attribute-only augmentation of existing wrapper). Skip brief-gate, A-dup, pre-PR-hold per POC lean pipeline.
- **Assumed:** `USER_ACTIVITY_BLOCK_PLUGIN_ID` scoping is stable; block renders once per profile page. (Verified via HelpText.php:403 comment describing single-block placement.)
- **Evidence:** DoStreamsHooks.php:589-620 (existing preprocessBlock); do-chrome-permission-matrix.html.twig:27 (analogous tooltip attach); js/do_chrome.tooltips.js:20 (global `[data-do-tooltip]` binder).

## A — Phase 3 (up-front plan review) — PASS
- **Verdict:** PASS. Zero block findings.
- **Verified:** preprocessBlock extension is the right surface (already plugin_id-guarded, already writes attributes + #attached — 3-line delta). Wrapper-level `data-do-tooltip` + `tabindex="0"` mirrors do-chrome-permission-matrix.html.twig:27 exactly; tooltips.js:20 binder is element-agnostic. do_chrome dep is the intended resolution of HelpText.php:414's deferral (SD-4/#131 named this consumer-wiring as the backstop) — not a boundary violation. No duplication; extends the analogous consumer via existing hook.
- **#193 contention:** info.yml dep-line union only; disjoint files and keys.

## T — Phase 4 (RED)
- **Decided:** Single Kernel test (`ProfileActivityTooltipTest::testProfileActivityBlockWrapperCarriesTooltipAndPreservesExistingBehavior`), one render pass, invoking `DoStreamsHooks::preprocessBlock()` directly against a hand-built `$variables` array — mirrors `StreamsShellTest`'s established direct-invocation convention (bypass block/theme render pipeline; assert on the mutated array, not rendered markup). Cheapest sufficient tier: no rendering/routing/DB-entity fixtures needed since the hook's own contract is a pure array mutation guarded by `plugin_id`.
- **Decided:** 5 assertions in one test (not 5 separate test methods) — brief explicitly requires "Kernel test asserts all four attributes/attachments in one render pass"; the 5th assertion (existing wrapper class preserved) pins the no-regression AC in the same pass, since both old and new behavior come from the same single `preprocessBlock()` call.
- **Setup note (non-blocking, environment only):** this worktree's `.ddev/config.yaml` had a stale `name: gm145-wcag` collision with another running worktree project. Renamed to `name: gm194-paconsumer` and ran `ddev start` — this only touches DDEV project naming, not test/source content. `vendor/` does not exist on the host filesystem; `composer install` and all PHP/PHPUnit invocations must run via `ddev exec`/`ddev composer`, with `SIMPLETEST_DB='mysql://db:db@db/db'` and `SIMPLETEST_BASE_URL='http://gm194-paconsumer.ddev.site'` supplied inline (phpunit.xml.dist ships both empty).
- **Evidence — RED confirmed:** `ddev exec "SIMPLETEST_DB='mysql://db:db@db/db' SIMPLETEST_BASE_URL='http://gm194-paconsumer.ddev.site' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/ProfileActivityTooltipTest.php"` → 1 test, 26 assertions run, 1 Failure (not Error): `Failed asserting that an array has the key 'data-do-tooltip'.` at ProfileActivityTooltipTest.php:88 — the exact new-behavior assertion the feature must satisfy. Fails for the right reason: `DoStreamsHooks::preprocessBlock()` pre-#194 does not set `data-do-tooltip`/`tabindex`/`do_chrome/tooltips`, so the test reaches and exercises the real method body (module loads, class instantiates, guard passes) and only the assertion on the NEW behavior fails.

## F — Phase 5 (implement)
- **Decided:** Implemented the exact minimal diff specified: `use Drupal\do_chrome\HelpText;` import (alphabetical, before `do_group_pin`); three body lines in `preprocessBlock()` after the existing class + library attach (`data-do-tooltip`, `tabindex`, `do_chrome/tooltips` attach); `do_chrome:do_chrome` in `do_streams.info.yml` deps, placed after `do_group_pin:do_group_pin` (the file's existing three custom deps aren't mutually alphabetized, so re-sorting them would be an unrelated, out-of-scope diff — anchored on the brief's suggested placement instead).
- **Decided:** No twig/markup changes, no new template/class/method — pure extension of the named analogous consumer per the brief's Reuse map.
- **Investigated (not a decision, a diagnostic):** PHPCS on the modified `DoStreamsHooks.php` reports 1 error + 8 warnings. Confirmed via a real-path baseline swap (`git show HEAD:... > <real path>`, re-run PHPCS, restore) that all 9 findings are byte-for-byte pre-existing (same docblock error, same `\Drupal::service()` GlobalDrupal warnings in unrelated methods), just shifted ~11-16 lines by my insertions. An earlier scratch-subdirectory copy misleadingly showed 0 GlobalDrupal warnings on the baseline — traced to a path/namespace artifact of running PHPCS against a copy outside its real module path, not a real difference; resolved by testing at the exact real file path instead. Zero net-new PHPCS findings from this change; left pre-existing findings untouched (out of scope for this issue).
- **Evidence — GREEN confirmed:** target test now passes (`Tests: 1, Assertions: 31` — up from T's RED 26, zero Failures/Errors; only pre-existing deprecation notices). Full `do_streams` Kernel suite regression check: `Tests: 52, Assertions: 1541`, zero Failures/Errors. PHPCS: `do_streams.info.yml` clean; `DoStreamsHooks.php`'s 1 error + 8 warnings confirmed pre-existing (see above), not introduced by this diff.
- **Files changed:** `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php`, `docs/groups/modules/do_streams/do_streams.info.yml`. No test files touched (T's `ProfileActivityTooltipTest.php` authored, unmodified by F). Handoff at `docs/handoffs/pa-consumer-194/handoff-F.md`.

## U (2026-07-24) — live-browser walkthrough verdict: PASS
Drove `/user/2` (maria_chen, 5 published nodes) on `https://gm194-paconsumer.ddev.site`
via Playwright chromium (headless). Confirmed on the real DOM: wrapper
`.do-streams-profile-activity` carries `data-do-tooltip=` HelpText copy verbatim +
`tabindex="0"` + `data-once="do-chrome-tooltip"` (bound once by the shared library).
`window.tippy` present; `.tippy-box` appears on hover AND on keyboard focus with the
same copy; dismisses on mouse-out. Zero console errors. Repro on 360×800 viewport
identical. WCAG 2.2 AA: 2.1.1 + 1.4.13 + 4.1.2 satisfied by pattern reuse (matches
PermissionMatrixPanel precedent). Ready for S. Evidence + full checklist in
`handoff-U.md`; screenshots in `evidence/`.

## S (2026-07-24) — spec audit verdict: REWORK (git hygiene only)
All six acceptance criteria verified against the in-scope diff (DoStreamsHooks.php lines 632-634 + info.yml line 15 + ProfileActivityTooltipTest.php with 6 assertions in one render pass). HelpText.php untouched (append-only contract respected); sibling #193 surface (stream-card variants, do-streams-shell.html.twig) not touched. F handoff matches actual diff; U evidence PNGs on disk. **Blocking:** (1) nothing committed — `git log origin/main..HEAD` empty. (2) Branch base `d014e29` is behind `origin/main` `9203cda` (#133 SD-6 merged after cut) — `git diff origin/main` currently shows spurious "reversions" of #133 that are actually just the stale base; must rebase (clean fast-forward — verified `origin/main`'s DoStreamsHooks::preprocessBlock is still pre-#194 and info.yml has no `do_chrome` line, so F's edits apply without conflict). (3) Staging is partial: test + T-red + decisions staged, but DoStreamsHooks/info.yml/brief/F+U handoffs/evidence still unstaged; plus dozens of unrelated modified/untracked files (rebase-noise, expected to evaporate post-rebase); plus a T-authored `.ddev/config.yaml` project rename (`gm145-wcag` → `gm194-paconsumer`) that is environment-only, not in brief — recommend revert before commit unless O keeps it deliberately. No REWORK on code content — only git hygiene. Handoff at `handoff-S.md`.

## O — Chain Summary (post-merge)
- **Outcome:** PR #201 merged as 4f3638e on 2026-07-24. Main advanced, branch auto-deleted.
- **Key decisions:** Extend `preprocessBlock()` (not a twig override); attach `data-do-tooltip` on the block outer wrapper (whole-section trigger); add `do_chrome:do_chrome` as hard dep (resolving HelpText.php:414 deferral).
- **CI:** 3/3 checks SUCCESS; mergeable CLEAN.
- **Open assumptions still unverified:** None — U walkthrough proved live browser binding at both viewports; Kernel test covers all 4 ACs; full do_streams suite regression clean.
- **Follow-ups filed:** None. Sibling #193 (SD-4 stream-card tooltips) is a separate, non-colliding story.
