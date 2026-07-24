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
   * #133 (SD-6 capstone, honesty sweep): SD-6 flipped every remaining
   * `coming` entry (membership-models, group-type homepages,
   * private-group-reveal, persona-switcher, stream-model) to `live`. The
   * former `testComingEntriesHaveNoRoute` iterated an always-empty `coming`
   * subset post-flip and asserted nothing (0-assertion risky test) — this
   * replaces it with the more valuable inverse invariant: the catalog must
   * contain NO `coming` entries at all. If a future story reintroduces a
   * `coming` entry, this fails loud with a useful message rather than
   * silently passing vacuously.
   *
   * @covers ::entries
   */
  public function testNoEntriesAreComing(): void {
    $entries = $this->catalog->entries();
    $this->assertGreaterThan(0, count($entries), 'The catalog must not be empty (guards against a vacuous pass below).');
    foreach ($entries as $entry) {
      $this->assertNotSame('coming', $entry['status'], sprintf('Entry "%s" is still "coming" — SD-6 flipped all to live.', $entry['id']));
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
   * #133 (SD-6 capstone, honesty sweep — work-list #8): the membership-models
   * entry flips [coming] -> [live]. Request-to-join (Moderated) and the
   * invite-only create-access gate both shipped and went live under #121
   * (SC-2) — the entry's OLD [coming] status is now stale/dishonest
   * (brief.md scope item 3: "/showcase tour page accurately lists all
   * shipped comparisons ... nothing 'coming' that already shipped").
   *
   * RED reason: `ShowcaseCatalog::entries()` still returns
   * `status: 'coming'` / `route: NULL` for 'membership-models' at RED time —
   * this assertion fails until F flips it (13-item work-list #8).
   *
   * @covers ::entries
   */
  public function testMembershipModelsEntryIsLive(): void {
    $entries = $this->catalog->entries();
    $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === 'membership-models'));
    $this->assertNotFalse($entry);
    $this->assertSame('live', $entry['status'], 'membership-models must flip to live — request-to-join (Moderated) and the invite-only create-access gate are both live and enforced (#121 SC-2); the old [coming] status is stale (#133 honesty sweep).');
    $this->assertNotNull($entry['route'], 'membership-models must carry a real route now that it is live (no dead link).');
  }

  /**
   * #133 (SD-6 capstone, honesty sweep — work-list #9/#10): group-type-
   * homepages and private-group-reveal both flip [coming] -> [live] — the
   * underlying features shipped under #122 (SC-3) and #134 (SC-7)
   * respectively, so their catalog entries are stale exactly like
   * membership-models above.
   *
   * RED reason: both entries still return `status: 'coming'` / `route: NULL`
   * at RED time — fails until F flips them (13-item work-list #9, #10).
   *
   * @covers ::entries
   */
  public function testGroupTypeHomepagesAndPrivateGroupRevealAreLive(): void {
    $entries = $this->catalog->entries();
    foreach (['group-type-homepages', 'private-group-reveal'] as $id) {
      $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === $id));
      $this->assertNotFalse($entry, sprintf('Entry "%s" must exist.', $id));
      $this->assertSame('live', $entry['status'], sprintf('"%s" must flip to live — the underlying feature is shipped and enforced; the old [coming] status is stale (#133 honesty sweep).', $id));
      $this->assertNotNull($entry['route'], sprintf('"%s" must carry a real route now that it is live (no dead link).', $id));
    }
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
    // #133 (SD-6 capstone, honesty sweep — work-list #12): the fourth
    // persona's `name` field must read "Groups-Moderate", not the stale
    // "Moderator" label — brief.md scope item 3 ("personas are
    // Anonymous/Member/Organizer/Groups-Moderate"). RED reason: `personas()`
    // still returns `'name' => 'Moderator'` at RED time — this assertion
    // fails until F flips it (13-item work-list #12).
    foreach (['Anonymous', 'Elena Garcia', 'Maria Chen', 'Groups-Moderate'] as $expected) {
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

  /**
   * #133 (SD-6 capstone, honesty sweep — work-list #11): Maria Chen's persona
   * description must read "A group Organizer." — the MVP-correct role name
   * (brief.md scope item 3), not the stale hedge "A group admin/organizer."
   * the description originally shipped.
   *
   * RED reason: `personas()`'s 'maria-chen' entry currently returns
   * `$this->t('A group admin/organizer.')` — this assertion fails until F
   * rewrites it (13-item work-list #11).
   *
   * @covers ::personas
   */
  public function testMariaChenPersonaDescriptionNamesOrganizerOnly(): void {
    $maria = $this->catalog->personaSpec('maria-chen');
    $this->assertNotNull($maria, 'The maria-chen persona must exist.');
    $description = (string) $maria['description'];
    $this->assertSame('A group Organizer.', $description, "Maria Chen's persona description must read exactly 'A group Organizer.' (#133 honesty sweep) — not the stale 'A group admin/organizer.' hedge.");
  }

  /**
   * ST-8 (#130): the stream-model entry flips `coming` -> `live`, with
   * route `view.activity_stream.page_1` and a decision_sentence naming the
   * ACTUAL comparison this story builds (node-content model vs.
   * activity-log model) — replacing the old, factually-wrong "single
   * combined activity stream vs. per-content-type streams" framing
   * (brief.md Amendment 1 / D's approved decision-sentence proposal,
   * handoff-D.md).
   *
   * RED reason (Phase 4): `ShowcaseCatalog::entries()` still returns the
   * OLD `coming`/NULL-route/stale-sentence entry until F flips it — this
   * assertion fails against that pre-existing code.
   *
   * @covers ::entries
   */
  public function testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence(): void {
    $entries = $this->catalog->entries();
    $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === 'stream-model'));
    $this->assertNotFalse($entry);

    $this->assertSame('live', $entry['status'], 'stream-model must flip to live — the switcher + Activity view are live (Content view is the only unavailable half).');
    $this->assertSame('view.activity_stream.page_1', $entry['route'], 'stream-model must route to the canonical Views auto-generated route id for /stream.');

    $decision_sentence = (string) $entry['decision_sentence'];
    $this->assertStringContainsString('node-content model', $decision_sentence, 'The corrected decision_sentence must name the node-content model half of the comparison.');
    $this->assertStringContainsString('activity-log model', $decision_sentence, 'The corrected decision_sentence must name the activity-log model half of the comparison.');
  }

}
