# Handoff-F: Phase 5 - #182 Seed pipeline: forum bundle needs comment field

**Date:** 2026-07-24
**Branch:** 182-forum-comment-field
**Issue:** #182

## What was done
- Created `docs/groups/config/field.field.node.forum.comment.yml` — cloned from `config/sync/field.field.node.article.comment.yml` per the brief's reuse map, with `bundle: forum`, `id: node.forum.comment`, `dependencies.config` swapped to `node.type.forum`, a new v4 uuid, `_core.default_config_hash` omitted, and `settings.anonymous: 0` preserved. This is the entirety of F's production scope per the brief.
- Ran `bash scripts/ci/assemble-config.sh` (via `ddev exec`), which copied the new file into `config/sync/field.field.node.forum.comment.yml` (132 files copied total, up from T's 131 — the +1 is this file).
- Verified the new YAML is correct by proving it programmatically via a throwaway diagnostic-only test copy (never shipped/committed — see "Tests that look wrong" below for why this was necessary and how it was cleaned up).
- Ran regression checks: full `do_discovery` + `do_streams` Kernel suites (53 tests / 1571 assertions) — no regressions anywhere outside the two known-gap tests.
- Ran phpcs on the `do_discovery` module directory — 0 new findings (pre-existing warnings in unrelated files only; the new file is YAML, not PHP-lint-checked, and lives outside `web/modules/`).

## Design decisions
- **Cloned rather than hand-authored** the field-instance YAML, matching the brief's explicit instruction and A's PASS. A `diff` against the article template confirms only 4 lines differ: `uuid`, the `node.type.*` dependency, `id`, and `bundle` — `_core.default_config_hash` was dropped (not swapped) because neither `field.field.node.forum.body.yml` nor `field.field.node.forum.field_group_tags.yml` (the two existing forum-bundle field precedents) carry that key, so omitting it here is the established convention, not a deviation from it.
- **No entity form/view display YAMLs added**, per A's PASS and the brief's non-goals — Drupal auto-generates the display, exactly as it already does for `node.forum.body` and `node.forum.field_group_tags`.
- **No changes to `do_discovery.install`, `DoDiscoveryHooks.php`, or the seed script.** The brief's AC #4 (seed script Step 740c falling through to the comment-seed loop) is satisfied by the YAML alone in the real seed-pipeline environment: that environment runs a full `drush`-driven module install, so `comment_install()`'s `hook_install()` runs normally and sets the `comment.maintain_entity_statistics` state key — the Kernel-test-specific gap described below does not apply there. Confirmed by re-reading `docs/groups/scripts/step_700_demo_data.php` lines 205-236: it queries `getFieldDefinitions('node', 'forum')` exactly like the AC #2 test and falls through to the 6-comment seed loop once the field is present.

## Reuse / extend-vs-new
Extended the analogous object named in the brief's Reuse map: `field.field.node.article.comment.yml` → cloned into `field.field.node.forum.comment.yml`. No new field storage, comment type, or module was created — all three already existed and were reused as-is, per the brief.

## Architecture notes for A
- One new config entity (`field_config`), no code changes, no schema changes, no new module dependencies. `comment` module was already enabled in `core.extension.yml`.
- No shared/other-agent-owned files were touched. `git status` on `docs/groups/config/` and `config/sync/field.field.node.forum.comment.yml` shows exactly the two new files (source + assembled) and nothing else.
- **Worth flagging for A/O:** confirmed via direct source reads (not assumption) that this codebase's Kernel test harness (`GroupsKernelTestBase` → `EntityKernelTestBase` → `KernelTestBase`) never performs a `config/sync`-style config-import and never runs `hook_install()` for modules enabled via `static::$modules` (`KernelTestBase::enableModules()` only rewrites `core.extension` and rebuilds the container/module list — see `web/core/tests/Drupal/KernelTests/KernelTestBase.php:832-876`). Every fixture in this test hierarchy (comment type, field storage, flags, node types, group types) is therefore built by explicit `EntityStorage::create()->save()` calls in each test's own `setUp()`, mirroring what a real `hook_install()` would do. This is a correct, intentional, already-established pattern in this codebase (`IcalFeedsTest`, `StreamsRankingTest` both do this) — not something to fix — but it means any new Kernel test that depends on a *bundle-specific* field config, or on any other module's `hook_install()` side effect (like `comment_install()`'s state-key write), must replicate that side effect explicitly in `setUp()`. This is exactly the shape of gap in `HotScoreForumCommentTest` below.

## Deviations from spec / wireframe
None from the brief/spec. No wireframe (data/config story, no UI surface).

## Tier 1 self-check (incl. tests now GREEN)

**Build:** N/A (config-only change, no PHP compiled). Config-sync side confirmed by presence of the file post-assemble:
```
$ ls config/sync/field.field.node.forum.comment.yml
config/sync/field.field.node.forum.comment.yml
```

**T's authored tests — still RED, for a reason outside F's scope (see "Tests that look wrong" below).** Ran the unmodified `HotScoreForumCommentTest.php` exactly as T documented:
```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php --testdox'
```
Output (unchanged from T's RED handoff — same failure text and line numbers):
```
Hot Score Forum Comment (Drupal\Tests\do_discovery\Kernel\HotScoreForumComment)
 ✘ Forum bundle has comment field
   │ The forum bundle has at least one field of type "comment" (field.field.node.forum.comment is attached).
   │ Failed asserting that an array is not empty.
   │ .../HotScoreForumCommentTest.php:137
 ✘ Forum node hot score is positive after comment and cron
   │ The forum node has a "comment" field to post against (field.field.node.forum.comment is attached).
   │ Failed asserting that false is true.
   │ .../HotScoreForumCommentTest.php:161

Tests: 2, Assertions: 44, Failures: 2, Deprecations: 3, PHPUnit Deprecations: 3.
```
This is *expected* given the diagnosis below: F's YAML is provably correct (verified via a throwaway diagnostic copy of the test with the two missing `setUp()` lines added — both tests passed once those lines were present), but the shipped test's `setUp()` never installs the `field_config` entity or the comment-statistics state flag into the Kernel test's isolated DB, so it cannot observe F's config change at all, regardless of what F ships. See "Tests that look wrong" for the exact fix T needs to make.

**No regressions — full `do_discovery` + `do_streams` Kernel suites:**
```
$ php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_discovery/tests/src/Kernel web/modules/custom/do_streams/tests/src/Kernel
...
There were 2 failures:
1) Drupal\Tests\do_discovery\Kernel\HotScoreForumCommentTest::testForumBundleHasCommentField
2) Drupal\Tests\do_discovery\Kernel\HotScoreForumCommentTest::testForumNodeHotScoreIsPositiveAfterCommentAndCron
...
Tests: 53, Assertions: 1571, Failures: 2, Deprecations: 24, PHPUnit Deprecations: 63.
```
The only 2 failures across both entire modules' Kernel suites are the two known-gap tests above (including `IcalFeedsTest`'s full 5/5, matching T's own baseline sanity check). No other test was affected by the new YAML.

**phpcs:**
```
$ ddev exec 'php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/do_discovery/'
```
0 findings attributable to the new file (it is YAML — phpcs does not lint it — and lives under `docs/groups/config/`, not `web/modules/`). Pre-existing warnings/errors reported in `DoDiscoveryHooks.php` and `IcalFeedsTest.php` are unrelated to this change (unmodified files).

## Tests that look wrong (for T)

`docs/groups/modules/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php`'s `setUp()` is missing two lines needed to make the test observe F's field-instance config at all. **Not edited per pipeline convention (F does not touch T's tests) — flagging here for T to fix in Phase 6.**

Root-cause chain (confirmed empirically, not by inspection alone — see `decisions.md` "F — Phase 5" for the full evidence trail):

1. **`testForumBundleHasCommentField` needs an explicit `field_config` install.** `setUp()` (lines 68-76) already installs `comment_type` and `field_storage_config` from shipped config via `FileStorage`, but never installs the `field_config` entity for `node.forum.comment`. This codebase's Kernel harness (`GroupsKernelTestBase`/`EntityKernelTestBase`) never does a generic config-import — confirmed by reading `KernelTestBase::enableModules()` (`web/core/tests/Drupal/KernelTests/KernelTestBase.php:832`), which only rewrites `core.extension` and rebuilds the container, never installing any module's config. `IcalFeedsTest::setUp()` (lines 102-111) is the exact working precedent already in this codebase: it installs BOTH `field_storage_config` and `field_config` for `field_date_of_event` from shipped config. **Fix:** add, right after the existing `field_storage_config` install (after line 76):
   ```php
   $entity_type_manager->getStorage('field_config')
     ->create($shipped->read('field.field.node.forum.comment'))
     ->save();
   ```

2. **`testForumNodeHotScoreIsPositiveAfterCommentAndCron` additionally needs `\Drupal::state()->set('comment.maintain_entity_statistics', TRUE)`.** Even with fix (1) in place, this test still fails — not on the `hasField()` guard anymore, but on the final score assertion (`0.0` is not `> 0`). Root cause: `\Drupal\comment\CommentStatistics::update()` (core, `web/core/modules/comment/src/CommentStatistics.php:209`) — the method that recalculates `comment_entity_statistics.comment_count` when a comment is saved — early-returns without writing anything if the state key `comment.maintain_entity_statistics` is falsy. That key is normally set to `TRUE` by `comment_install()`'s `hook_install()` (`web/core/modules/comment/comment.install:30`), but Kernel tests never run `hook_install()` for modules in `static::$modules` (same `enableModules()` finding as above). Confirmed empirically via a throwaway diagnostic assertion: immediately after `$comment->save()`, `\Drupal::state()->get('comment.maintain_entity_statistics')` was `NULL` and the `comment_entity_statistics` row for the node did not exist at all (not even a zeroed row). **Fix:** add, anywhere in `setUp()` (e.g. alongside the schema installs near the top):
   ```php
   \Drupal::state()->set('comment.maintain_entity_statistics', TRUE);
   ```

Both fixes are additive to `setUp()` only — no change to either test method body, and no change needed to F's YAML (verified correct: a throwaway diagnostic copy of the test with exactly these two lines added, run against F's shipped `field.field.node.forum.comment.yml`, passed both tests cleanly). The diagnostic copy was created only in the assembled `web/modules/custom/` tree (a gitignored build artifact) and in this agent's scratchpad, was never added to `docs/groups/modules/`, and was deleted immediately after use — nothing from it should appear in `git status`.

## Known issues
None against F's own scope (the field-instance YAML). The two tests remain RED pending T's Phase 6 fix to `setUp()` (see above) — this is expected and does not represent an F gap; F's config was verified correct independently of the shipped test.

## Files changed
- `docs/groups/config/field.field.node.forum.comment.yml` (new)
- `config/sync/field.field.node.forum.comment.yml` (new — assembled output of the above via `scripts/ci/assemble-config.sh`, per this repo's build-artifact convention)
