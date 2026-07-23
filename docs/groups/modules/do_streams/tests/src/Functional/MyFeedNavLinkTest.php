<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Behavioral test for the new "My Feed" nav-link entry seeded by
 * `step_780_nav_menu.php` (issue #110, ST-1, AC-8).
 *
 * The brief instructs `step_780_nav_menu.php` to APPEND a 5th
 * `menu_link_content` entry keyed `st1-nav-my-feed`, `uri: internal:/my-feed`,
 * weighted so it sits between Activity (existing weight 1) and My Groups
 * (existing weight 2). handoff-A.md Finding #8 flags that a weight of `1.5`
 * (the wireframe's Q-D2 resolution) is a FLOAT and `menu_link_content.weight`
 * is an INTEGER field in core — a float will be silently coerced, and F must
 * therefore re-weight the existing links surgically (integer scheme) to
 * preserve the intended Activity < My Feed < My Groups < Create Group order,
 * OR use an already-free integer. This suite pins the OBSERVABLE outcome
 * (final integer ordering), not a specific weight value, so it stays valid
 * regardless of which surgical re-weight scheme F picks.
 *
 * Requires literally including the seed script (it is a bare procedural
 * script executed by `drush php:script`, not a class/service), mirroring how
 * `docs/groups/scripts/step_*.php` files are designed to run against a live
 * bootstrapped Drupal — BrowserTestBase's self-installed site gives us that
 * live environment. This is a NEW file; no existing nav test is edited (per
 * this story's non-negotiables).
 *
 * `step_780_nav_menu.php` does not exist as a callable unit outside the
 * runbook; requiring it here is the same pattern used by
 * `docs/groups/scripts/step_780_nav_menu.php` itself (a plain script with
 * top-level code) — PHPUnit's `require` executes it once per test-method
 * process boot, matching its own idempotency contract (skip on existing
 * `description` key).
 *
 * Layer/setUp correction (T's own RED-authoring bug, fixed before RED was
 * reported as valid): the original revision called
 * `$this->installEntitySchema('menu_link_content')` in setUp(), a
 * KernelTestBase-only method that does not exist on BrowserTestBase —
 * BrowserTestBase::setUp() already installs a FULL site (every entity schema
 * for every module in `$modules`, including `menu_link_content`), so that
 * call is both unnecessary and undefined on this base class. It failed with
 * "Call to undefined method ...installEntitySchema()" BEFORE reaching any
 * real assertion — an invalid RED masking the intended one. Removed; no
 * schema installation call is needed here.
 *
 * Path-resolution correction (also fixed before RED was reported valid): the
 * repo-root walk-up from THIS file (in the ASSEMBLED layout,
 * `web/modules/custom/do_streams/tests/src/Functional/`) is 7 directory
 * levels, not 6 — `dirname(__DIR__, 7)` lands on the repo root
 * (`/var/www/html`), confirmed against both the assembled path and the
 * source-tree path (`docs/groups/modules/do_streams/tests/src/Functional/`,
 * also exactly 7 levels below the repo root), so the same constant resolves
 * correctly in both layouts.
 *
 * RED reason: the script currently defines only 4 links (`ch83-nav-groups`,
 * `ch83-nav-activity`, `ch83-nav-my-groups`, `ch83-nav-create-group`) — no
 * `st1-nav-my-feed` entry exists yet, so every assertion below (existence,
 * ordering, idempotency of a 5th link) fails until F appends it.
 *
 * @group do_streams
 * @group do_tests
 */
class MyFeedNavLinkTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['menu_link_content', 'menu_ui', 'system'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Absolute path to the nav-menu seed script under test.
   *
   * @var string
   */
  protected string $seedScriptPath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Resolve the seed script relative to the repo root by walking up from
    // THIS file — never a source-relative literal path (WAVE-EXECUTION-
    // HANDOFF.md §6.1's assembled-vs-source gotcha). Both the source tree
    // (docs/groups/modules/do_streams/tests/src/Functional/) and the
    // assembled tree (web/modules/custom/do_streams/tests/src/Functional/)
    // sit exactly 7 directory levels below the repo root, so a fixed
    // dirname(__DIR__, 7) resolves correctly in both layouts.
    $repoRoot = dirname(__DIR__, 7);
    $this->seedScriptPath = $repoRoot . '/docs/groups/scripts/step_780_nav_menu.php';
    $this->assertFileExists(
      $this->seedScriptPath,
      'The step_780_nav_menu.php seed script must exist at the resolved repo-root-relative path in both source and assembled layouts.',
    );
  }

  /**
   * Runs the seed script once (idempotent by its own contract).
   */
  protected function runSeedScript(): void {
    require $this->seedScriptPath;
  }

  /**
   * Loads every `menu_link_content` entity keyed by its stable `description`.
   *
   * @return array<string, \Drupal\menu_link_content\Entity\MenuLinkContent>
   *   Menu link entities keyed by their `description` (the stable seed key).
   */
  protected function loadLinksByKey(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
    $all = $storage->loadMultiple();
    $byKey = [];
    foreach ($all as $link) {
      $byKey[$link->getDescription()] = $link;
    }
    return $byKey;
  }

  /**
   * AC-8: a `st1-nav-my-feed` link exists after seeding, pointing at /my-feed.
   */
  public function testMyFeedNavLinkIsSeeded(): void {
    $this->runSeedScript();
    $links = $this->loadLinksByKey();

    $this->assertArrayHasKey(
      'st1-nav-my-feed',
      $links,
      'A menu_link_content entity keyed st1-nav-my-feed exists after seeding.',
    );
    $this->assertSame(
      'internal:/my-feed',
      $links['st1-nav-my-feed']->getUrlObject()->toUriString(),
      'The st1-nav-my-feed link points at internal:/my-feed.',
    );
    $this->assertSame(
      'My Feed',
      (string) $links['st1-nav-my-feed']->getTitle(),
      'The st1-nav-my-feed link is titled "My Feed".',
    );
  }

  /**
   * AC-8: weights are plain integers (handoff-A.md Finding #8) and impose
   * Groups < Activity < My Feed < My Groups < Create Group.
   */
  public function testNavLinkWeightsAreIntegersAndOrderedCorrectly(): void {
    $this->runSeedScript();
    $links = $this->loadLinksByKey();

    foreach (['ch83-nav-groups', 'ch83-nav-activity', 'st1-nav-my-feed', 'ch83-nav-my-groups', 'ch83-nav-create-group'] as $key) {
      $this->assertArrayHasKey($key, $links, "A menu_link_content entity keyed $key exists after seeding.");
    }

    $weights = [];
    foreach ($links as $key => $link) {
      $weight = $link->getWeight();
      $this->assertIsInt(
        $weight,
        sprintf('The weight of "%s" must be a plain integer (handoff-A.md Finding #8 — a float weight is silently coerced).', $key),
      );
      $weights[$key] = $weight;
    }

    $this->assertLessThan(
      $weights['st1-nav-my-feed'],
      $weights['ch83-nav-activity'],
      'Activity\'s weight is strictly less than My Feed\'s (My Feed sits after Activity).',
    );
    $this->assertLessThan(
      $weights['ch83-nav-my-groups'],
      $weights['st1-nav-my-feed'],
      'My Feed\'s weight is strictly less than My Groups\' (My Feed sits before My Groups).',
    );
    $this->assertLessThan(
      $weights['ch83-nav-create-group'],
      $weights['ch83-nav-my-groups'],
      'My Groups\' weight is strictly less than Create Group\'s.',
    );
    $this->assertLessThan(
      $weights['st1-nav-my-feed'],
      $weights['ch83-nav-groups'],
      'Groups\' weight is strictly less than My Feed\'s (Groups still sorts first overall).',
    );
  }

  /**
   * Idempotency: re-running the seed does not create a duplicate My Feed link.
   */
  public function testReSeedingDoesNotDuplicateMyFeedLink(): void {
    $this->runSeedScript();
    $this->runSeedScript();

    $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
    $matches = $storage->loadByProperties(['description' => 'st1-nav-my-feed']);

    $this->assertCount(
      1,
      $matches,
      'Re-running the seed script does not create a duplicate st1-nav-my-feed link.',
    );
  }

}
