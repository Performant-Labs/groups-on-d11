# Handoff-T-green: Phase 6 - #182 Seed pipeline: forum bundle needs comment field

**Date:** 2026-07-24
**Branch:** 182-forum-comment-field
**Issue:** #182
**Handoff-F reviewed:** `docs/planning/handoffs/182-forum-comment/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/182-forum-comment/handoff-T-red.md`

## Fixture fix applied

F's root-cause diagnosis (handoff-F.md "Tests that look wrong") was confirmed correct by direct
code inspection (`KernelTestBase::enableModules()`, `CommentStatistics::update()`,
`comment.install`) and empirically by re-running the amended test. Two lines were added to
`HotScoreForumCommentTest::setUp()` in
`docs/groups/modules/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php`. No assertion in
either test method was touched.

**Before (setUp(), relevant excerpt):**
```php
$this->installEntitySchema('comment');
$this->installSchema('comment', ['comment_entity_statistics']);
$this->installSchema('do_discovery', ['do_discovery_hot_score']);

$shipped = new FileStorage($this->shippedConfigDir());
$entity_type_manager = $this->container->get('entity_type.manager');

$entity_type_manager->getStorage('comment_type')
  ->create($shipped->read('comment.type.comment'))
  ->save();
$entity_type_manager->getStorage('field_storage_config')
  ->create($shipped->read('field.storage.node.comment'))
  ->save();
```

**After (two additions, both additive to setUp() only):**
```php
$this->installEntitySchema('comment');
$this->installSchema('comment', ['comment_entity_statistics']);
$this->installSchema('do_discovery', ['do_discovery_hot_score']);

// Fix #1 (state key comment_install() normally sets; Kernel tests skip hook_install()).
\Drupal::state()->set('comment.maintain_entity_statistics', TRUE);

$shipped = new FileStorage($this->shippedConfigDir());
$entity_type_manager = $this->container->get('entity_type.manager');

$entity_type_manager->getStorage('comment_type')
  ->create($shipped->read('comment.type.comment'))
  ->save();
$entity_type_manager->getStorage('field_storage_config')
  ->create($shipped->read('field.storage.node.comment'))
  ->save();

// Fix #2 (bundle-specific field_config instance; no generic config-import in this harness).
$entity_type_manager->getStorage('field_config')
  ->create($shipped->read('field.field.node.forum.comment'))
  ->save();
```

Full diff (22 lines added, 0 removed) is in the working tree at
`docs/groups/modules/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php`.

Note: an incidental CRLF/LF slip was introduced by the editing tool during the first pass and
caught by phpcs (`End of line character is invalid`); normalized to LF (matching the file's
original convention per `git show HEAD:<path>`) before re-running phpcs and phpunit. Final file
is pure LF.

## GREEN confirmation

Command:
```
ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php --testdox'
```

Output:
```
Hot Score Forum Comment (Drupal\Tests\do_discovery\Kernel\HotScoreForumComment)
 ✔ Forum bundle has comment field
 ✔ Forum node hot score is positive after comment and cron

Tests: 2, Assertions: 46, Deprecations: 3, PHPUnit Deprecations: 3.
```

Both Phase-4 tests (`testForumBundleHasCommentField`, `testForumNodeHotScoreIsPositiveAfterCommentAndCron`)
now pass. Assertion count rose from 44 (RED) to 46 (GREEN) — expected, since both tests now run
to completion including the previously-unreached assertions (comment-field-not-empty check,
score > 0 check). The 3 deprecations are pre-existing core-level noise (`RunTestsInSeparateProcesses`,
`cache.backend.memory`, `cache.static`), unrelated to this change and present in F's RED run too.

**Spot-check: tests still fail if behavior is removed.** Temporarily moved
`docs/groups/config/field.field.node.forum.comment.yml` and its assembled mirror
`config/sync/field.field.node.forum.comment.yml` out of the way, re-assembled, and re-ran:
```
Hot Score Forum Comment (Drupal\Tests\do_discovery\Kernel\HotScoreForumComment)
 ✘ Forum bundle has comment field
   │ TypeError: Drupal\Core\Entity\EntityStorageBase::create(): Argument #1 ($values) must be of
   │ type array, false given, called in .../HotScoreForumCommentTest.php on line 97
```
Confirms the test genuinely depends on F's shipped config (not a tautology). Restored both files,
re-assembled, re-ran — back to GREEN (see above). This also confirms the amended `setUp()` still
asserts real behavior, not implementation details.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Target test suite | `phpunit ... HotScoreForumCommentTest.php --testdox` | 2/2 pass | 2/2 pass, 46 assertions | PASS |
| Full do_discovery + do_streams Kernel suite | `phpunit ... web/modules/custom/do_discovery/tests/src/Kernel web/modules/custom/do_streams/tests/src/Kernel` | 53/53 pass, no regressions | 53 tests, 1573 assertions, 0 failures | PASS |
| phpcs (Drupal,DrupalPractice) on amended file | `phpcs --standard=Drupal,DrupalPractice .../HotScoreForumCommentTest.php` | 0 errors | 0 errors (after LF fix) | PASS |
| Config assemble | `ddev exec bash scripts/ci/assemble-config.sh` | succeeds, no manual config edits needed | 132 files copied, 15 modules copied, extension patch applied | PASS |

Regression sweep detail (full output tail):
```
Tests: 53, Assertions: 1573, Deprecations: 24, PHPUnit Deprecations: 63.
```
1573 assertions vs. F's reported 1571 — the +2 are the two newly-reachable assertions in the
amended tests (comment-field-not-empty, score>0), consistent with the RED→GREEN assertion delta
above. No new failures anywhere in either module's Kernel suite; `IcalFeedsTest` remains 5/5
(unaffected, as expected — it's the precedent pattern, not touched).

## Tier 2 results

- **Test coverage:** Both brief acceptance criteria under test (AC #2 field-attachment, AC #3
  hot-score-after-comment-and-cron) are backed by a passing Kernel test each. No new AC introduced
  in this phase; F's implementation was config-only. PASS.
- **Test quality:** Each test names a single behavior (field attachment; score recompute), fails
  in isolation for the right reason (verified both at RED — handoff-T-red.md — and via the
  Phase-6 spot-check above), sits at Kernel tier (cheapest sufficient — no HTTP/browser layer
  needed), and does not duplicate `StreamsRankingTest` (which seeds the score row directly on
  `page` nodes, not via the comment→cron path on `forum`). Suite size (2 tests) is proportionate
  to the change (one config file, two ACs) — no redundant tests to prune. PASS.
- **Type safety:** No PHP type errors in the amended file; `field_config` entity created via typed
  `EntityStorageInterface::create(array $values)` matching the existing `field_storage_config` and
  `comment_type` calls immediately above it. No `any`-equivalent casts. PASS.
- **Error handling:** Not applicable to this config-only story — no new error paths introduced by
  F's YAML. The test's own `hasField('comment')` assertion (line ~161-164) is itself an explicit
  guard that fails clearly (not a crash) if the field is absent, which was exercised directly in
  both the original RED and the Phase-6 spot-check. PASS.
- **Data integrity:** N/A — no schema/DB migration, single config-entity addition, no
  concurrent-write or duplicate-key surface introduced.
- **API contract:** N/A — no HTTP/API surface in this story.
- **Security:** N/A — no new input surface, no new access-control logic. `settings.anonymous: 0`
  preserved unchanged from the article template per F's handoff, matching existing forum-bundle
  field precedent.
- **Migration safety:** Config-only addition (new `field_config` entity), no destructive change,
  fully reversible via config revert / entity delete. Not a DB schema migration.
- **Playwright:** N/A — no UI surface (brief explicitly notes "no wireframe — data/config story,
  no UI surface"). Not run.

## Acceptance criteria status

| AC | Description | Test | Status |
|---|---|---|---|
| AC #2 | `getFieldDefinitions('node', 'forum')` includes a `comment`-type field once assembled config is installed | `testForumBundleHasCommentField` | PASS |
| AC #3 | Posting a comment on a forum node + running the real hot-score recompute (`DoDiscoveryHooks::cron()`) yields `do_discovery_hot_score.score > 0` | `testForumNodeHotScoreIsPositiveAfterCommentAndCron` | PASS |
| AC #4 (seed script Step 740c) | Not re-tested here (out of Kernel-test scope per F's handoff — real seed-pipeline env runs full `hook_install()`, so the Kernel-specific state-key gap does not apply there). F confirmed by direct read of `docs/groups/scripts/step_700_demo_data.php:205-236` that the query pattern matches AC #2's test exactly. No test regression risk introduced. | N/A (config-only, verified by F via code read) | Not blocking |

## Blocking issues

None.

## Advisory notes

- The CRLF slip during editing (caught by phpcs, corrected before commit) is worth noting for any
  future agent editing this file on Windows: verify line endings with `file <path>` after any
  scripted edit, since silent CRLF insertion is not visible in a normal diff review and will fail
  phpcs even though the PHP itself is correct.
- F's handoff (§"Design decisions") already covers why AC #4 doesn't need its own Kernel test;
  T concurs — the seed script's own field-existence guard is structurally identical to AC #2's
  assertion, and a full functional/e2e test of the seed pipeline is out of proportion for a
  single config-file addition.
