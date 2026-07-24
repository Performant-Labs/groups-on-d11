<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Unit;

use Drupal\do_showcase\ShowcaseCatalog;
use Drupal\Tests\UnitTestCase;

/**
 * #212 (REL-3, docs-repo parity) regression guard.
 *
 * Every ShowcaseCatalog entry MUST declare its relationship to the upstream
 * docs-repo feature tour (https://git.drupalcode.org/project/groupsdrupalorg
 * /-/issues/3578797) by carrying exactly one of:
 *
 *   - `upstream_ref` (string URL) — this entry mirrors an item on the
 *     upstream feature tour; the URL is the source of record.
 *   - `local_only => TRUE` — this entry is a deliberate local extension
 *     with no upstream counterpart.
 *
 * A future entry that omits both (or declares both) fails this test loud,
 * which is the whole point: the /showcase catalog and the docs-repo tour
 * drift silently otherwise, exactly the failure mode #196/#197/#198
 * demonstrated before this guard existed.
 *
 * @coversDefaultClass \Drupal\do_showcase\ShowcaseCatalog
 * @group do_showcase
 */
final class ShowcaseCatalogUpstreamRefTest extends UnitTestCase {

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
   * Every entry carries exactly one of `upstream_ref` OR `local_only => TRUE`.
   *
   * @covers ::entries
   */
  public function testEveryEntryDeclaresUpstreamRefOrLocalOnly(): void {
    foreach ($this->catalog->entries() as $entry) {
      $id = $entry['id'];
      $has_ref = array_key_exists('upstream_ref', $entry) && $entry['upstream_ref'] !== NULL && $entry['upstream_ref'] !== '';
      $has_local = array_key_exists('local_only', $entry) && $entry['local_only'] === TRUE;

      $this->assertTrue(
        $has_ref || $has_local,
        sprintf('Entry "%s" must declare either `upstream_ref` (URL) or `local_only => TRUE` — #212 docs-repo parity guard.', $id)
      );
      $this->assertFalse(
        $has_ref && $has_local,
        sprintf('Entry "%s" must NOT declare BOTH `upstream_ref` and `local_only` — pick one.', $id)
      );
    }
  }

  /**
   * Every `upstream_ref`, when set, is a well-formed upstream docs-repo URL.
   *
   * Pins the source of record so a typo (e.g. wrong issue number) cannot
   * pass silently.
   *
   * @covers ::entries
   */
  public function testUpstreamRefsPointAtDocsRepo(): void {
    foreach ($this->catalog->entries() as $entry) {
      if (!array_key_exists('upstream_ref', $entry) || $entry['upstream_ref'] === NULL) {
        continue;
      }
      $ref = (string) $entry['upstream_ref'];
      $this->assertStringStartsWith(
        'https://git.drupalcode.org/project/groupsdrupalorg/',
        $ref,
        sprintf('Entry "%s" upstream_ref must point at the groupsdrupalorg docs repo (source of record).', $entry['id'])
      );
    }
  }

}
