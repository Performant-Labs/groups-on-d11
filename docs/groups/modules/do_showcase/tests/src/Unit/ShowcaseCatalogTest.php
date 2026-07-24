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
 * NOT config/content — this test pins that contract plus the required
 * entries the brief's acceptance criteria name explicitly:
 *   discovery ranking, directory presentation, membership models,
 *   group-type homepages, stream model, private-group reveal (#134),
 *   the persona switcher (#120, naming all four public personas), and
 *   public-browse (#217, REL-3 parity — upstream feature-tour item #1,
 *   "Anonymous read access").
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
   * All required entries are present (six comparisons + persona switcher +
   * public-browse, #217 REL-3 parity), each a complete, typed shape.
   *
   * @covers ::entries
   */
  public function testAllRequiredEntriesArePresent(): void {
    $entries = $this->catalog->entries();
    $this->assertCount(8, $entries, 'Every required catalog entry is present.');

    $ids = array_column($entries, 'id');
    $expected = [
      'discovery-ranking',
      'directory-presentation',
      'membership-models',
      'group-type-homepages',
      'stream-model',
      'private-group-reveal',
      'persona-switcher',
      'public-browse',
    ];
    sort($ids);
    sort($expected);
    $this->assertSame($expected, $ids, 'All required comparison/persona entries must be present by id.');
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
   * description must name "Organizer" — the MVP-correct role name
   * (brief.md scope item 3), not the stale hedge "A group admin/organizer."
   * the description originally shipped.
   *
   * #220 (REL-3 parity, persona-name drift): the description is later
   * extended (see testMariaChenDescriptionCrossReferencesUpstreamGroupAdmin
   * below) to also cross-reference the upstream "Group Admin" role name.
   * This test is deliberately loosened from the post-#133 `assertSame(
   * 'A group Organizer.', ...)` to `assertStringContainsString('Organizer',
   * ...)` — the exact-match assertion pinned a stricter invariant (the FULL
   * string) than the property that actually matters (the honesty-sweep
   * intent: name Organizer, not the old admin/organizer hedge). Loosening
   * to a substring check lets #220's additive cross-reference text coexist
   * without re-litigating #133's already-settled outcome.
   *
   * @covers ::personas
   */
  public function testMariaChenPersonaDescriptionNamesOrganizer(): void {
    $maria = $this->catalog->personaSpec('maria-chen');
    $this->assertNotNull($maria, 'The maria-chen persona must exist.');
    $description = (string) $maria['description'];
    $this->assertStringContainsString('Organizer', $description, "Maria Chen's persona description must name 'Organizer' (#133 honesty sweep) — not the stale 'A group admin/organizer.' hedge.");
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

  /**
   * #198 (docs parity): the directory-presentation entry's decision_sentence
   * currently frames the comparison as list-vs-cards only, omitting the Map
   * variant that #125 SC-6 (Directory map view) shipped as live
   * (VariantSwitcher::directoryLayoutOptionIds() already carries 'map' with
   * no 'available => FALSE'; HelpText.php:169 already names all three). This
   * copy string is the one stale reference on the user-visible /showcase
   * page (brief.md #198).
   *
   * Keyword choice: 'geograph' (matches 'geographically'/'geographic') is
   * used as the authoritative axis-keyword rather than accepting "location"
   * or "plot" as alternates, because 'geograph' is the one word HelpText.php
   * :169 already uses for this exact variant ('Map plots groups
   * geographically') — pinning the same root keeps the two copy surfaces
   * conceptually aligned instead of letting them drift into different
   * vocabularies for the same concept. The assertion separately requires
   * 'Map' by name, so the fix must both name the variant AND describe its
   * axis.
   *
   * RED reason: `ShowcaseCatalog::entries()` still returns the OLD
   * decision_sentence ('Compares list vs. card layouts for the group
   * directory — the decision: information density vs. visual
   * scannability.') at RED time — no 'Map', no 'geograph' — this assertion
   * fails until F rewrites line 52 to name Map and the geographic axis.
   *
   * @covers ::entries
   */
  public function testDirectoryPresentationEntryNamesMapVariant(): void {
    $entries = $this->catalog->entries();
    $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === 'directory-presentation'));
    $this->assertNotFalse($entry);

    $decision_sentence = (string) $entry['decision_sentence'];
    $this->assertStringContainsString('Map', $decision_sentence, 'The directory-presentation decision_sentence must name Map as the third variant (#125 SC-6 shipped it live; #198 docs parity).');
    $this->assertStringContainsString('geograph', $decision_sentence, "The directory-presentation decision_sentence must mention the geographic/plotting axis Map introduces (mirrors HelpText.php:169's 'geographically' wording).");
  }

  /**
   * #217 (REL-3, docs-parity reconciliation): a new explicit `public-browse`
   * catalog entry mirrors upstream feature-tour item #1 ("Anonymous read
   * access") — today this is only IMPLICIT via the Anonymous persona on the
   * persona-switcher entry, with no dedicated catalog entry naming anonymous
   * read access as its own comparison.
   *
   * RED reason: `ShowcaseCatalog::entries()` at RED time contains no
   * `public-browse` entry — this fails until F adds it (#217 REL-3 parity
   * reconciliation).
   *
   * @covers ::entries
   */
  public function testPublicBrowseEntryIsLive(): void {
    $entries = $this->catalog->entries();
    $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === 'public-browse'));
    $this->assertNotFalse($entry, 'A public-browse catalog entry must exist (#217 REL-3 parity — upstream feature-tour item #1, "Anonymous read access").');
    $this->assertSame('live', $entry['status'], 'public-browse must be live — anonymous read access already works today.');
    $this->assertNotNull($entry['route'], 'public-browse must carry a real route (no dead link).');
    $this->assertArrayHasKey('upstream_ref', $entry, 'public-browse must carry an upstream_ref pointing at the upstream feature-tour item it mirrors.');
    $this->assertNotEmpty((string) $entry['upstream_ref'], "public-browse's upstream_ref must be non-empty.");
  }

  /**
   * #218 (REL-3, docs-parity reconciliation): the group-type-homepages
   * entry's `decision_sentence` is currently abstract ("a generic group page
   * vs. a type-tailored homepage") while the upstream feature tour, AND this
   * repo's own `HelpText.php:321` copy ("Events lead with the event
   * calendar, Discussion leads with the stream, Documentation leads with the
   * reference index"), both name three concrete variants. This test pins
   * that the catalog's user-visible copy names the same three variants
   * HelpText.php already does, closing the drift.
   *
   * RED reason: at RED time the entry's decision_sentence is abstract
   * ('generic group page vs. a type-tailored homepage') and does not name
   * the three variants — fails until F rewrites the copy (#218 REL-3
   * parity, mirrors HelpText.php:321 which already names them).
   *
   * @covers ::entries
   */
  public function testGroupTypeHomepagesEntryNamesThreeVariants(): void {
    $entries = $this->catalog->entries();
    $entry = current(array_filter($entries, static fn (array $e): bool => $e['id'] === 'group-type-homepages'));
    $this->assertNotFalse($entry);

    $decision_sentence = (string) $entry['decision_sentence'];
    foreach (['events-first', 'discussion-first', 'docs-first'] as $variant) {
      $this->assertStringContainsString($variant, $decision_sentence, sprintf('group-type-homepages decision_sentence must name the "%s" variant (#218 REL-3 parity, mirrors HelpText.php:321).', $variant));
    }
  }

  /**
   * #220 (REL-3, docs-parity reconciliation, part 1 of 2): persona-name
   * drift — upstream names this role "Group Admin"; this repo names it
   * "Organizer". Rather than renaming (a much larger, riskier change), the
   * chosen reconciliation is a cross-reference: maria-chen's persona
   * `description` is extended to also name the upstream role, so a reader
   * comparing the two docs sets can see the correspondence explicitly.
   *
   * RED reason: at RED time maria-chen's description is exactly "A group
   * Organizer." (post-#133) — fails until F extends it with an upstream
   * cross-reference (#220 REL-3 parity).
   *
   * @covers ::personas
   */
  public function testMariaChenDescriptionCrossReferencesUpstreamGroupAdmin(): void {
    $maria = $this->catalog->personaSpec('maria-chen');
    $this->assertNotNull($maria, 'The maria-chen persona must exist.');
    $description = (string) $maria['description'];
    $this->assertStringContainsString('Group Admin', $description, "maria-chen's persona description must cross-reference the upstream 'Group Admin' role name (#220 REL-3 parity, persona-name drift).");
  }

  /**
   * #220 (REL-3, docs-parity reconciliation, part 2 of 2): the same
   * cross-reference treatment for the moderator persona — upstream names
   * this role "Moderator"; this repo names it "Groups-Moderate". The
   * persona `description` is extended to name the upstream role.
   *
   * RED reason: at RED time the description is exactly "A site-wide
   * moderation role." — fails until F extends it with an upstream
   * cross-reference.
   *
   * @covers ::personas
   */
  public function testGroupsModeratePersonaDescriptionCrossReferencesUpstreamModerator(): void {
    $moderator = $this->catalog->personaSpec('moderator');
    $this->assertNotNull($moderator, 'The moderator persona must exist.');
    $description = (string) $moderator['description'];
    $this->assertStringContainsString('Moderator', $description, 'The moderator persona description must cross-reference the upstream "Moderator" role name (#220 REL-3 parity, persona-name drift).');
  }

}
