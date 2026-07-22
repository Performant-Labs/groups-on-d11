<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Unit;

use Drupal\do_showcase\ShowcaseCatalog;
use Drupal\Tests\UnitTestCase;

/**
 * Unit coverage for the SC-F1 (#119) ShowcaseCatalog code-constant list.
 *
 * Brief-gate B-4 (ACCEPTED): the comparison list and persona list are typed
 * PHP-array code constants (`{id, title, decision_sentence, status, route}`),
 * NOT config/content — this test pins that contract plus the specific seven
 * entries the brief's acceptance criteria name explicitly:
 *   discovery ranking, directory presentation, membership models,
 *   group-type homepages, stream model, private-group reveal (#134),
 *   and the persona switcher (#120, naming all four public personas).
 *
 * @coversDefaultClass \Drupal\do_showcase\ShowcaseCatalog
 * @group do_showcase
 */
final class ShowcaseCatalogTest extends UnitTestCase {

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
   * All seven required entries are present (six comparisons + persona
   * switcher), each a complete, typed shape.
   *
   * @covers ::entries
   */
  public function testAllSevenRequiredEntriesArePresent(): void {
    $entries = $this->catalog->entries();
    $this->assertCount(7, $entries, 'Exactly seven entries: six comparisons + the persona switcher.');

    $ids = array_column($entries, 'id');
    $expected = [
      'discovery-ranking',
      'directory-presentation',
      'membership-models',
      'group-type-homepages',
      'stream-model',
      'private-group-reveal',
      'persona-switcher',
    ];
    sort($ids);
    sort($expected);
    $this->assertSame($expected, $ids, 'All seven required comparison/persona entries must be present by id.');
  }

  /**
   * Every entry carries the full typed shape: id, title, decision_sentence,
   * status (live|coming), route (nullable).
   *
   * @covers ::entries
   */
  public function testEveryEntryHasCompleteShape(): void {
    foreach ($this->catalog->entries() as $entry) {
      $this->assertArrayHasKey('id', $entry);
      $this->assertArrayHasKey('title', $entry);
      $this->assertArrayHasKey('decision_sentence', $entry);
      $this->assertArrayHasKey('status', $entry);
      $this->assertArrayHasKey('route', $entry);
      $this->assertContains($entry['status'], ['live', 'coming'], sprintf('Entry "%s" status must be live or coming.', $entry['id']));
      $this->assertNotEmpty((string) $entry['title'], sprintf('Entry "%s" must have a non-empty title.', $entry['id']));
      $this->assertNotEmpty((string) $entry['decision_sentence'], sprintf('Entry "%s" must have a non-empty one-sentence decision framing.', $entry['id']));
    }
  }

  /**
   * `coming` entries carry NO route (no dead link) — truthful-copy rule
   * (wireframe.md: "coming entries show no dead link ... never a link to a
   * page that doesn't exist yet").
   *
   * @covers ::entries
   */
  public function testComingEntriesHaveNoRoute(): void {
    foreach ($this->catalog->entries() as $entry) {
      if ($entry['status'] === 'coming') {
        $this->assertNull($entry['route'], sprintf('"Coming" entry "%s" must not carry a route (no dead link).', $entry['id']));
      }
    }
  }

  /**
   * `live` entries DO carry a route (a real deep-link target).
   *
   * @covers ::entries
   */
  public function testLiveEntriesHaveARoute(): void {
    foreach ($this->catalog->entries() as $entry) {
      if ($entry['status'] === 'live') {
        $this->assertNotNull($entry['route'], sprintf('"Live" entry "%s" must carry a route.', $entry['id']));
      }
    }
  }

  /**
   * The membership-models entry stays [coming] — O-notes-for-F.md: "request-
   * to-join is bespoke in #121; grequest is incompatible with group 4.0.x.
   * Do not imply it's live."
   *
   * @covers ::entries
   */
  public function testMembershipModelsEntryStaysComing(): void {
    $entries = $this->catalog->entries();
    $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === 'membership-models'));
    $this->assertNotFalse($entry);
    $this->assertSame('coming', $entry['status'], 'membership-models must stay [coming] — request-to-join is not yet built (grequest incompatible with group 4.0.x, per #136).');
  }

  /**
   * The private-group-reveal entry references issue #134 in its decision
   * sentence (brief.md Acceptance criterion names this explicitly).
   *
   * @covers ::entries
   */
  public function testPrivateGroupRevealEntryReferencesIssue134(): void {
    $entries = $this->catalog->entries();
    $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === 'private-group-reveal'));
    $this->assertNotFalse($entry);
    $this->assertStringContainsString('134', (string) $entry['decision_sentence'] . (string) ($entry['title'] ?? ''), 'private-group-reveal entry must reference #134 (brief.md Acceptance criterion).');
  }

  /**
   * The persona-switcher entry names all four public personas: Anonymous,
   * Elena Garcia, Maria Chen, Moderator (brief.md Acceptance criterion,
   * wireframe.md Surface 2).
   *
   * @covers ::entries
   */
  public function testPersonaSwitcherEntryNamesAllFourPersonas(): void {
    $personas = $this->catalog->personas();
    $names = array_map(static fn (array $p): string => (string) $p['name'], $personas);
    $this->assertCount(4, $personas, 'Exactly four public personas.');
    foreach (['Anonymous', 'Elena Garcia', 'Maria Chen', 'Moderator'] as $expected) {
      $this->assertContains($expected, $names, sprintf('Persona list must name "%s".', $expected));
    }
  }

  /**
   * The persona-switcher catalog entry itself is `live` (the switcher device
   * exists this story; SC-4/5/6 real comparisons remain "coming").
   *
   * @covers ::entries
   */
  public function testPersonaSwitcherEntryIsLive(): void {
    $entries = $this->catalog->entries();
    $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === 'persona-switcher'));
    $this->assertNotFalse($entry);
    $this->assertSame('live', $entry['status']);
  }

  /**
   * All user-facing strings are TranslatableMarkup (t()-wrapped) — Brief-gate
   * B-4/W-3 (i18n): "All user-facing strings wrapped in t() for localization."
   *
   * @covers ::entries
   */
  public function testEntryStringsAreTranslatableMarkup(): void {
    foreach ($this->catalog->entries() as $entry) {
      $this->assertInstanceOf(
        \Drupal\Core\StringTranslation\TranslatableMarkup::class,
        $entry['title'],
        sprintf('Entry "%s" title must be t()-wrapped (TranslatableMarkup), not a raw string.', $entry['id'])
      );
      $this->assertInstanceOf(
        \Drupal\Core\StringTranslation\TranslatableMarkup::class,
        $entry['decision_sentence'],
        sprintf('Entry "%s" decision_sentence must be t()-wrapped (TranslatableMarkup), not a raw string.', $entry['id'])
      );
    }
  }

}
