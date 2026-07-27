<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_extras\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;

/**
 * #251 Fix 3 — `views.view.all_groups`'s DEFAULT display must declare
 * `cache: type: none` (PERF-3 audit gap, structural/config half).
 *
 * RED (Phase 4, authored by T before F implements). Regression test for
 * `cache-audit-2026-07-24.md` §4 Recommendation 3(a): the audit's own
 * recommended smallest-diff fix for `/all-groups`'s private-group
 * cache-tag gap is a 1-line YAML change — `all_groups.yml` adopting
 * `cache: type: none`, mirroring the EXISTING precedent already set by
 * three sibling views for the identical reason (per-viewer-varying content
 * whose row-exclusion mechanism cannot attach its own cache tag):
 * `my_feed.yml:141-143`, `trending.yml:115-117`, `following_feed.yml:118-120`
 * (all three verified to already carry `cache: type: none` in this exact
 * repo, confirmed by direct read).
 *
 * Layer choice: Kernel (in fact, no Drupal bootstrap is strictly required
 * for this assertion — it is a pure YAML-structure read — but a Kernel test
 * is the cheapest tier this codebase already has a "read shipped config
 * directly" convention for, mirroring
 * {@see \Drupal\Tests\do_discovery\Kernel\HotScoreForumCommentTest::shippedConfigDir()}'s
 * identical directory-walk pattern, so this suite reuses that established
 * approach rather than inventing a bespoke plain-PHPUnit-TestCase
 * mechanism). This is INTENTIONALLY the cheapest-sufficient tier: Fix 3 is
 * a config-only change (per the brief: "Config-only change... 1-line YAML
 * change"), so a Functional round-trip is unnecessary to pin the fix
 * itself — {@see \Drupal\Tests\do_group_extras\Functional\AllGroupsMembershipCacheInvalidationTest}
 * already covers the BEHAVIORAL half (member-vs-non-member correctness +
 * the negative anonymous-leak control) at the Functional tier, where an
 * HTTP round-trip is actually required to observe viewer-scoped behavior.
 * This test does not duplicate those — it pins the STRUCTURAL contract
 * (the config value itself) that the audit's own recommendation names as
 * the fix, independent of whether any single Functional scenario happens
 * to exercise a real cache HIT/MISS in a given test environment.
 *
 * @group do_group_extras
 * @group group
 */
class AllGroupsViewCachePluginTest extends KernelTestBase {

  /**
   * Resolves the shipped config directory for this test.
   *
   * Walks up from this file, checking both the canonical
   * `docs/groups/config` and the assembled `config/sync` at each level, so
   * it resolves identically whether running from the source tree or an
   * assembled CI layout — verbatim mirror of
   * `HotScoreForumCommentTest::shippedConfigDir()`'s pattern (same
   * rationale, same directory-walk shape, same marker-file technique).
   *
   * @return string
   *   Absolute path to the directory holding the shipped config.
   */
  protected function shippedConfigDir(): string {
    $marker = 'views.view.all_groups.yml';
    $dir = __DIR__;
    while ($dir !== '' && $dir !== DIRECTORY_SEPARATOR) {
      foreach (['docs/groups/config', 'config/sync'] as $candidate) {
        $path = $dir . '/' . $candidate;
        if (is_file($path . '/' . $marker)) {
          return $path;
        }
      }
      $dir = dirname($dir);
    }
    $this->fail("Could not locate shipped $marker in docs/groups/config or config/sync above " . __DIR__);
  }

  /**
   * Fix 3 (audit §4 Rec 3(a)): the `all_groups` view's DEFAULT display
   * (the display embedded by the `/all-groups` page — see `page_1`'s own
   * `display_options` inheriting from `default` per Views' own inheritance
   * model) must declare `cache: type: none`, matching the exact precedent
   * `my_feed`/`trending`/`following_feed` already set in this repo.
   */
  public function testAllGroupsDefaultDisplayDeclaresCacheTypeNone(): void {
    $shipped = new FileStorage($this->shippedConfigDir());
    $data = $shipped->read('views.view.all_groups');
    $this->assertNotFalse($data, 'views.view.all_groups.yml is readable from the shipped config directory.');

    $cache = $data['display']['default']['display_options']['cache'] ?? NULL;

    $this->assertIsArray(
      $cache,
      'Fix 3: views.view.all_groups\'s default display must declare a `cache` key at all — today it declares none (audit §1 coverage table / §4 Rec 3(a)), silently defaulting to Views core\'s `tag` plugin, which cannot see rows the query-alter excluded.',
    );
    $this->assertSame(
      'none',
      $cache['type'] ?? NULL,
      'Fix 3: views.view.all_groups\'s default display cache plugin must be `none`, matching the exact precedent already set by my_feed.yml/trending.yml/following_feed.yml for the identical per-viewer-varying-content reason.',
    );
  }

}
