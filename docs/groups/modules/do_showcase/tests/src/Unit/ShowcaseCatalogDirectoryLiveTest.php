<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Unit;

use Drupal\do_showcase\ShowcaseCatalog;
use Drupal\Tests\UnitTestCase;

/**
 * #124 SC-5: the `directory-presentation` catalog entry flips to `live`.
 *
 * Pins the brief.md acceptance criterion: "`/showcase` catalog entry
 * `directory-presentation` — TODAY marked `coming` in
 * `ShowcaseCatalog::entries()` — flips to `status: live` with `route:
 * view.all_groups.page_1`" (route id confirmed by handoff-A-plan.md
 * advisory #4, citing `PageHelp.php:72` +
 * `PageHelpRouteMapTest.php:46` as the canonical route id for the
 * `/all-groups` page display).
 *
 * A companion, NARROWER test than `ShowcaseCatalogTest` (which already pins
 * the catalog's general shape/rules, e.g. "coming entries carry no route" —
 * this test does not duplicate those general-shape assertions; it pins only
 * the ONE entry this story's acceptance criterion names). Kept as its own
 * file (rather than appended to `ShowcaseCatalogTest`) so a reader can find
 * the SC-5-specific behavior pin without wading through the SC-F1 (#119)
 * catalog-wide test file, and so T-green can re-run just this file when
 * verifying this story in isolation.
 *
 * RED-by-construction: `ShowcaseCatalog::entries()` today (per
 * `ShowcaseCatalog.php:47-52`) still returns
 * `directory-presentation` with `status: 'coming'` and `route: NULL` — this
 * test fails on the wrong STATUS VALUE ('coming' !== 'live'), not on a
 * missing entry or a setup error, which is the right reason for a RED here.
 *
 * @coversDefaultClass \Drupal\do_showcase\ShowcaseCatalog
 * @group do_showcase
 */
final class ShowcaseCatalogDirectoryLiveTest extends UnitTestCase {

  /**
   * The catalog under test.
   */
  private ShowcaseCatalog $catalog;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->catalog = new ShowcaseCatalog();
    $this->catalog->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * Locates the `directory-presentation` entry, failing loudly if absent
   * (rather than silently producing FALSE and failing the caller's own
   * assertion for an unrelated reason).
   */
  private function directoryPresentationEntry(): array {
    $entries = $this->catalog->entries();
    $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === 'directory-presentation'));
    $this->assertNotFalse($entry, 'The directory-presentation entry must exist in ShowcaseCatalog::entries().');
    return $entry;
  }

  /**
   * The entry's status is `live` (was `coming`).
   *
   * @covers ::entries
   */
  public function testDirectoryPresentationEntryIsLive(): void {
    $entry = $this->directoryPresentationEntry();
    $this->assertSame(
      'live',
      $entry['status'],
      'directory-presentation must flip to status "live" — SC-5 (#124) ships the compact/cards toggle on /all-groups.'
    );
  }

  /**
   * The entry's route is `view.all_groups.page_1` — the canonical Views
   * auto-generated route id for the `/all-groups` page display (confirmed
   * by handoff-A-plan.md advisory #4: `PageHelp.php:72` +
   * `PageHelpRouteMapTest.php:46` both key on this exact route id).
   *
   * @covers ::entries
   */
  public function testDirectoryPresentationEntryRoutesToAllGroupsPage(): void {
    $entry = $this->directoryPresentationEntry();
    $this->assertSame(
      'view.all_groups.page_1',
      $entry['route'],
      'directory-presentation must route to view.all_groups.page_1 (the /all-groups page), not do_showcase.showcase.'
    );
  }

}
