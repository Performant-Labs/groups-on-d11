# Handoff-T-red: Phase 4 - #182 Seed pipeline: forum bundle needs comment field

**Date:** 2026-07-24
**Branch:** 182-forum-comment-field
**Brief / wireframe reviewed:** `docs/planning/handoffs/182-forum-comment/brief.md`, `docs/planning/handoffs/182-forum-comment/survey.md`, `docs/planning/handoffs/182-forum-comment/handoff-A.md` (no wireframe — data/config story, no UI surface)

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3, `handoff-A.md`). Two `warn`-level (non-blocking) findings were incorporated directly into the test design below:
1. There is no `do_discovery_recalculate_hot_scores()` function; recalc lives only in `DoDiscoveryHooks::cron()` (`#[Hook('cron')]`). The test invokes this method directly via the class resolver, not a nonexistent helper.
2. `comment_entity_statistics` is auto-updated by core on comment save, but the `do_discovery_hot_score` row is only written/merged by cron — the test calls `DoDiscoveryHooks::cron()` explicitly after posting the comment.

## Environment setup (this run)

- This worktree's `.ddev/config.yaml` `name:` was `gm129-activity`, a stale collision with another worktree's registered DDEV project (`groups-st3-events-112`). Renamed to `gm182-forumcomment` (unique to this worktree) and ran `ddev start` — this is a local dev-environment fix only, not part of the story's scope, and does not affect CI (CI does not use DDEV).
- `vendor/` was missing (no prior `composer install` in this worktree). Ran `ddev composer install --no-interaction` — succeeded (169 packages).
- `bash scripts/ci/assemble-config.sh` must run **inside** the ddev container (`ddev exec 'bash scripts/ci/assemble-config.sh'`) since PHP is not on the host PATH. Copied 131 config files (7 env-specific excluded) and 15 custom modules into `web/modules/custom/`.
- Kernel bootstrap requires `SIMPLETEST_DB` (mirroring `.github/workflows/test.yml`'s kernel job) and a minimal `web/sites/default/settings.php` (hash_salt + config_sync_directory). DDEV's DB credentials are `db`/`db`@`db:3306`/`db` (mariadb). Command used for every run in this handoff:
  ```
  ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php --testdox'
  ```
- Sanity check: ran the pre-existing `IcalFeedsTest` (do_discovery) first with this exact setup — 5/5 passed (177 assertions, only deprecation noise), confirming the environment itself is sound before authoring new tests against it.

## Tests authored

Both live in `docs/groups/modules/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php` (namespace `Drupal\Tests\do_discovery\Kernel`, extends `GroupsKernelTestBase`, mirrors `IcalFeedsTest`'s `shippedConfigDir()` walk-up pattern and `StreamsRankingTest`'s comment-schema install pattern).

| Test | Acceptance criterion pinned | Tier | Why this tier |
|---|---|---|---|
| `testForumBundleHasCommentField` | AC #2 — `getFieldDefinitions('node', 'forum')` includes a `comment`-type field | Kernel | Cheapest sufficient tier: this is a field-definition/config-attachment fact, fully exercisable via `entity_field.manager` without HTTP or a browser. |
| `testForumNodeHotScoreIsPositiveAfterCommentAndCron` | AC #3 — posting a comment on a forum node + running the real hot-score recompute (`DoDiscoveryHooks::cron()`) yields `do_discovery_hot_score.score > 0` | Kernel | Exercises the real DB-backed hook logic (`comment_entity_statistics` join + merge into `do_discovery_hot_score`) directly; no UI/HTTP layer is under test, so Kernel is sufficient and cheapest. |

Neither test duplicates existing coverage: no existing kernel/functional test asserts on the forum bundle's field list (confirmed by A's blast-radius review) or on hot-score-after-forum-comment specifically (`StreamsRankingTest::testHotRankingOrdersByScoreDesc` seeds the score row directly via `seedHotScore()`, on `page` nodes — it does not exercise the comment→cron→score path on `forum`).

## RED confirmation

Command:
```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php --testdox'
```

Output (failures, deprecation noise elided):
```
Hot Score Forum Comment (Drupal\Tests\do_discovery\Kernel\HotScoreForumComment)
 ✘ Forum bundle has comment field
   │
   │ The forum bundle has at least one field of type "comment" (field.field.node.forum.comment is attached).
   │ Failed asserting that an array is not empty.
   │
   │ .../web/modules/custom/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php:137
   │
 ✘ Forum node hot score is positive after comment and cron
   │
   │ The forum node has a "comment" field to post against (field.field.node.forum.comment is attached).
   │ Failed asserting that false is true.
   │
   │ .../web/modules/custom/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php:161
   │

Tests: 2, Assertions: 44, Failures: 2, Deprecations: 3, PHPUnit Deprecations: 3.
```

**Both fail for the right reason.** `testForumBundleHasCommentField` fails because `getFieldDefinitions('node', 'forum')` genuinely returns no `comment`-type field today (the array-filter is empty) — not a class-not-found or setup exception. `testForumNodeHotScoreIsPositiveAfterCommentAndCron` fails on its own explicit guard assertion (`$node->hasField('comment')` is `FALSE`) *before* it ever attempts to create the `Comment` entity or invoke cron — so the failure is squarely "the field does not exist yet," not an incidental crash further down the test (posting a comment against a nonexistent field would throw, which would have been a weaker/noisier RED). 44 assertions executed successfully across both tests before the two intentional failures, confirming setUp()/bootstrap/fixtures are sound (comment module, comment type, comment field storage, group/forum node type, group role/permissions all installed without error).

phpcs (`Drupal,DrupalPractice` standards) on the test file: 0 errors, 0 warnings (fixed two initial findings — a two-sentence doc-comment first line, and a method name `testForumBundleHasACommentField` that DrupalPractice's lowerCamel sniff mis-flagged on the "ACommentField" capital run — renamed to `testForumBundleHasCommentField`).

## Ready for F

**Confirmed RED is valid; F may implement against these tests.** F's task per the brief: add `docs/groups/config/field.field.node.forum.comment.yml` (cloned from `config/sync/field.field.node.article.comment.yml` per the reuse map — `bundle: forum`, `node.type.forum` dependency, `settings.anonymous: 0`, regenerated `uuid`). No changes to `DoDiscoveryHooks.php`, the seed script, or any other test are anticipated; A's blast-radius review found no other test surface touching the forum field list.
