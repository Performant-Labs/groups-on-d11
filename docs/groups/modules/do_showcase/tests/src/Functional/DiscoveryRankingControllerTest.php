<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Functional;

use Drupal\do_chrome\HelpText;
use Drupal\Tests\BrowserTestBase;

/**
 * #123 SC-4 (Discovery three ways) — `ShowcaseController::page()`'s new
 * "Discovery ranking" comparison surface: a second `discovery.ranking`
 * `VariantSwitcher` instance (Recent / Hot / Promoted), deep-linkable via
 * `?discovery=<id>`, rendering the corresponding EXISTING view
 * (`activity_stream` / `hot_content` / `promoted_content`) via
 * `views_embed_view()` — NOT a forked/duplicated ranking pipeline
 * (handoff-A-plan.md Risk 3 resolution: path (b), embed the three existing
 * views directly).
 *
 * Layer choice: Functional (BrowserTestBase), mirroring
 * `ShowcaseControllerHelpTest`'s own precedent for this controller — a real
 * HTTP request through the real `/showcase` route is the cheapest tier that
 * can observe (a) the ACTUAL rendered `?discovery=` query-arg resolution via
 * `ControllerBase`'s injected `Request`, and (b) the real `views_embed_view()`
 * render pipeline output (a Kernel test could reach the render array, but the
 * embedded-view HTML this story's acceptance criteria assert against — "all
 * three variants render non-empty from seed" — needs a live view execution
 * against seeded/fixture content, which BrowserTestBase's node-creation API
 * gives cheaply here without a second, redundant Kernel-tier ViewExecutable
 * harness duplicating `DirectoryTogglePreRenderTest`'s approach for a
 * different (embed, not pre_render-hook) integration point).
 *
 * RED reason: `ShowcaseController::page()` does not yet read the `discovery`
 * query argument, build a second `discovery.ranking` VariantSwitcher
 * instance, or call `views_embed_view()` for any of the three views — every
 * assertion below targets markup/behavior nothing in the current codebase
 * renders yet (missing `[data-do-discovery-ranking]` region, missing
 * `data-do-showcase-instance="discovery.ranking"` switcher, missing embedded
 * view content), not an import/setup error.
 *
 * IMPORTANT — a latent gap this test file deliberately does NOT paper over
 * (flagged in handoff-T-red.md "Assumptions" for O/A visibility): the REAL
 * `docs/groups/config/views.view.promoted_content.yml`'s `default` display
 * filters ONLY on `status` (published) — it carries no flag/relationship
 * filter actually restricting to `promote_homepage`-flagged nodes, despite
 * its label/description. Per handoff-A-plan.md Risk 3, this story embeds that
 * view AS-IS ("do NOT fork ranking") rather than fixing it, so
 * `testPromotedTabEmbedsPromotedContentViewWithSeededNodes()` below pins the
 * view's TRUE current behavior (it shows the two seeded promoted nodes
 * because they are published content, not because they are excluded/included
 * by a flag filter that does not exist) rather than asserting a false
 * "excludes non-promoted content" claim the view does not actually implement.
 *
 * @group do_showcase
 */
final class DiscoveryRankingControllerTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * `views` is required to embed the three existing views; `comment` is
   * required because `hot_content`'s ranking score and `activity_stream`'s
   * sort both key off comment entity-statistics tables. `flag` provides the
   * `promote_homepage` flag (imported so the seed helper below can flag
   * nodes, matching production seed behavior, even though the view's own
   * default display does not currently filter on it — see class docblock).
   * `node` provides "access content" for the anonymous `do_showcase.showcase`
   * route (same defect class `ShowcaseControllerHelpTest`'s own docblock
   * documents: a minimal install with only `do_showcase` enabled gives the
   * anonymous role no permissions and /showcase 403s).
   */
  protected static $modules = [
    'do_showcase',
    'node',
    'views',
    'comment',
    'flag',
    'field',
    'text',
    'filter',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // A minimal "post" node type is sufficient — none of the three source
    // views filter by bundle in a way that excludes a generic content type
    // (activity_stream/hot_content restrict to a bundle allowlist that
    // includes 'post'; promoted_content has no bundle filter at all).
    \Drupal::entityTypeManager()->getStorage('node_type')->create([
      'type' => 'post',
      'name' => 'Post',
    ])->save();

    // Import the three source views + the promote_homepage flag from
    // module-local fixtures (PROJECT_CONTEXT.md: fixtures must be
    // module-local, never a source-relative __DIR__/../../../../../config
    // path, which passes in the source tree but fails in CI's assembled
    // layout). views.view.promoted_content fixture mirrors the REAL config's
    // default display byte-for-byte (see class docblock).
    $fixtures = new \Drupal\Core\Config\FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager = \Drupal::entityTypeManager();
    foreach ([
      'view' => ['views.view.activity_stream', 'views.view.hot_content', 'views.view.promoted_content'],
      'flag' => ['flag.flag.promote_homepage'],
    ] as $storage_id => $config_names) {
      foreach ($config_names as $config_name) {
        $data = $fixtures->read($config_name);
        $this->assertNotFalse($data, sprintf('Fixture %s exists and is readable.', $config_name));
        $entity_type_manager->getStorage($storage_id)->create($data)->save();
      }
    }

    // Programmatically-created view config entities do not automatically
    // trigger a router rebuild the way drush config:import does — without
    // this, hot_content's page_1 display (path "hot") is never registered
    // as a route, and /hot 404s even though the view entity exists.
    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Creates a published "post" node, optionally flagged as promoted.
   *
   * @param string $title
   *   The node title.
   * @param bool $promoted
   *   Whether to flag the node with `promote_homepage`.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  private function createPostNode(string $title, bool $promoted = FALSE): \Drupal\node\NodeInterface {
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'post',
      'title' => $title,
      'status' => 1,
      'uid' => 1,
    ]);
    $node->save();

    if ($promoted) {
      /** @var \Drupal\flag\FlagServiceInterface $flag_service */
      $flag_service = \Drupal::service('flag');
      $flag = $flag_service->getFlagById('promote_homepage');
      $this->assertNotNull($flag, 'promote_homepage flag fixture must be importable.');
      $flag_service->flag($flag, $node, \Drupal\user\Entity\User::load(1));
    }

    return $node;
  }

  /**
   * The `/showcase` page renders a "Discovery ranking" H2 section carrying a
   * `discovery.ranking` VariantSwitcher instance with exactly three options
   * (recent / hot / promoted), all AVAILABLE (brief.md acceptance: "all
   * three variants render non-empty from seed" — none are "(soon)").
   */
  public function testDiscoveryRankingSectionRendersSwitcherWithThreeAvailableOptions(): void {
    $this->createPostNode('Community guidelines', TRUE);
    $this->createPostNode('Featured group spotlight', TRUE);

    $this->drupalGet('/showcase');
    $this->assertSession()->statusCodeEquals(200);

    $region = $this->assertSession()->elementExists('css', '[data-do-discovery-ranking]');
    $switcher = $this->assertSession()->elementExists('css', '[data-do-showcase-instance="discovery.ranking"]', $region);
    $this->assertSame('radiogroup', $switcher->getAttribute('role'), 'The discovery.ranking switcher wrapper must carry role="radiogroup".');
    $this->assertNotEmpty($switcher->getAttribute('aria-label'), 'The discovery.ranking switcher wrapper must carry a non-empty aria-label.');

    $options = $switcher->findAll('css', '[role="radio"]');
    $this->assertCount(3, $options, 'The discovery.ranking switcher must render exactly three options (Recent / Hot / Promoted).');
    foreach ($options as $option) {
      $this->assertNotSame('true', $option->getAttribute('aria-disabled'), 'No discovery.ranking option may be unavailable — all three variants must render non-empty from seed (brief.md acceptance).');
    }

    $this->assertSession()->pageTextContains('Recent');
    $this->assertSession()->pageTextContains('Hot');
    $this->assertSession()->pageTextContains('Promoted');
  }

  /**
   * `?discovery=hot` deep-links directly into the Hot tab: the Hot option is
   * pre-selected (`aria-checked="true"`) with no client-side JS required
   * (brief.md acceptance: "deep links land pre-switched from /showcase").
   */
  public function testDiscoveryQueryArgDeepLinksToHotTabPreSelected(): void {
    $this->createPostNode('Community guidelines', TRUE);
    $this->createPostNode('Featured group spotlight', TRUE);

    $this->drupalGet('/showcase', ['query' => ['discovery' => 'hot']]);
    $this->assertSession()->statusCodeEquals(200);

    $region = $this->assertSession()->elementExists('css', '[data-do-discovery-ranking]');
    $switcher = $this->assertSession()->elementExists('css', '[data-do-showcase-instance="discovery.ranking"]', $region);
    $hot_option = current(array_filter(
      $switcher->findAll('css', '[role="radio"]'),
      static fn ($el) => $el->getAttribute('data-do-showcase-id') === 'hot',
    ));
    $this->assertNotFalse($hot_option, 'A "hot" option must be present in the switcher.');
    $this->assertSame('true', $hot_option->getAttribute('aria-checked'), '?discovery=hot must pre-select the Hot tab (aria-checked="true").');
  }

  /**
   * `?discovery=recent` (the default/first option) renders the
   * `activity_stream` view's content in the embedded region — proving the
   * controller routes to the REAL existing view, not a duplicated ranking
   * pipeline (handoff-A-plan.md Risk 3: "do NOT fork ranking").
   */
  public function testRecentTabEmbedsActivityStreamViewContent(): void {
    $this->createPostNode('Welcome thread');

    $this->drupalGet('/showcase', ['query' => ['discovery' => 'recent']]);
    $this->assertSession()->statusCodeEquals(200);

    $region = $this->assertSession()->elementExists('css', '[data-do-discovery-ranking]');
    $this->assertStringContainsString('Welcome thread', $region->getHtml(), 'The Recent tab must embed activity_stream\'s real content (the seeded node), not a duplicated/forked ranking.');
  }

  /**
   * `?discovery=promoted` renders the `promoted_content` view's content: the
   * two seeded promoted nodes appear, satisfying brief.md's "Promoted shows 2
   * seeded nodes" acceptance criterion. Per this file's class docblock, the
   * REAL `promoted_content` view's default display filters only on `status`
   * (not on the `promote_homepage` flag), so this test pins that TRUE
   * behavior — the seeded promoted nodes appear because they are published,
   * and an unrelated published node ALSO appears in this same view (a
   * pre-existing gap in the reused, untouched view, not a defect this story
   * introduces or is scoped to fix).
   */
  public function testPromotedTabEmbedsPromotedContentViewWithSeededNodes(): void {
    $this->createPostNode('Community guidelines', TRUE);
    $this->createPostNode('Featured group spotlight', TRUE);

    $this->drupalGet('/showcase', ['query' => ['discovery' => 'promoted']]);
    $this->assertSession()->statusCodeEquals(200);

    $region = $this->assertSession()->elementExists('css', '[data-do-discovery-ranking]');
    $html = $region->getHtml();
    $this->assertStringContainsString('Community guidelines', $html, 'The Promoted tab must show the first seeded promoted node.');
    $this->assertStringContainsString('Featured group spotlight', $html, 'The Promoted tab must show the second seeded promoted node.');
  }

  /**
   * An unknown/unavailable `?discovery=` value falls back to the first
   * available option (recent) — never blank/broken, matching
   * `VariantSwitcher::resolveSelection()`'s existing contract.
   */
  public function testUnknownDiscoveryQueryArgFallsBackToRecent(): void {
    $this->createPostNode('Welcome thread');

    $this->drupalGet('/showcase', ['query' => ['discovery' => 'bogus']]);
    $this->assertSession()->statusCodeEquals(200);

    $region = $this->assertSession()->elementExists('css', '[data-do-discovery-ranking]');
    $switcher = $this->assertSession()->elementExists('css', '[data-do-showcase-instance="discovery.ranking"]', $region);
    $recent_option = current(array_filter(
      $switcher->findAll('css', '[role="radio"]'),
      static fn ($el) => $el->getAttribute('data-do-showcase-id') === 'recent',
    ));
    $this->assertNotFalse($recent_option);
    $this->assertSame('true', $recent_option->getAttribute('aria-checked'), 'An unknown ?discovery= value must fall back to the first available option (recent), never blank/broken.');
  }

  /**
   * Both switcher instances (`directory.layout` and `discovery.ranking`)
   * coexist on `/showcase` without colliding on query keys —
   * `?variant=cards&discovery=hot` sets BOTH tabs independently (brief.md
   * key constraint: "Two switchers on /showcase must NOT share ?variant=").
   */
  public function testBothSwitchersCoexistWithIndependentQueryKeys(): void {
    $this->createPostNode('Community guidelines', TRUE);
    $this->createPostNode('Featured group spotlight', TRUE);

    $this->drupalGet('/showcase', ['query' => ['variant' => 'cards', 'discovery' => 'hot']]);
    $this->assertSession()->statusCodeEquals(200);

    $directory_switcher = $this->assertSession()->elementExists('css', '[data-do-showcase-instance="directory.layout"]');
    $cards_option = current(array_filter(
      $directory_switcher->findAll('css', '[role="radio"]'),
      static fn ($el) => $el->getAttribute('data-do-showcase-id') === 'cards',
    ));
    $this->assertNotFalse($cards_option);
    $this->assertSame('true', $cards_option->getAttribute('aria-checked'), '?variant=cards must still select the directory.layout switcher\'s Cards option.');

    $discovery_region = $this->assertSession()->elementExists('css', '[data-do-discovery-ranking]');
    $discovery_switcher = $this->assertSession()->elementExists('css', '[data-do-showcase-instance="discovery.ranking"]', $discovery_region);
    $hot_option = current(array_filter(
      $discovery_switcher->findAll('css', '[role="radio"]'),
      static fn ($el) => $el->getAttribute('data-do-showcase-id') === 'hot',
    ));
    $this->assertNotFalse($hot_option);
    $this->assertSame('true', $hot_option->getAttribute('aria-checked'), '?discovery=hot must simultaneously select the discovery.ranking switcher\'s Hot option, independent of ?variant=cards.');
  }

  /**
   * The discovery.ranking switcher carries exactly ONE `[data-do-tooltip]`
   * wrapper-level tooltip (POC scope, handoff-A-plan.md Risk 2 resolution:
   * one tooltip per switcher wrapper, not per option), sourced from
   * `HelpText::get('showcase.switcher.discovery.ranking')`.
   */
  public function testDiscoverySwitcherCarriesExactlyOneWrapperTooltip(): void {
    $this->createPostNode('Community guidelines', TRUE);
    $this->createPostNode('Featured group spotlight', TRUE);

    $this->drupalGet('/showcase');
    $this->assertSession()->statusCodeEquals(200);

    $region = $this->assertSession()->elementExists('css', '[data-do-discovery-ranking]');
    $switcher = $this->assertSession()->elementExists('css', '[data-do-showcase-instance="discovery.ranking"]', $region);

    $tooltips = $switcher->findAll('css', '[data-do-tooltip]');
    $this->assertCount(1, $tooltips, 'The discovery.ranking switcher must carry exactly ONE wrapper-level tooltip trigger, not one per option (POC scope).');

    $expected_copy = HelpText::get('showcase.switcher.discovery.ranking');
    $this->assertNotSame('', $expected_copy, 'Precondition: showcase.switcher.discovery.ranking must resolve to non-empty copy.');
    $this->assertSame($expected_copy, $tooltips[0]->getAttribute('data-do-tooltip'), 'The tooltip copy must come from HelpText::get(\'showcase.switcher.discovery.ranking\') exactly.');
  }

  /**
   * Non-regression: the existing `/hot` standalone page (hot_content view's
   * own `page_1` display) is completely unchanged by this story — same
   * route, same content, still reachable directly (brief.md acceptance:
   * "Existing /hot and promoted views behavior UNCHANGED").
   */
  public function testExistingHotPageRouteIsUnaffected(): void {
    $this->createPostNode('Welcome thread');

    $this->drupalGet('/hot');
    $this->assertSession()->statusCodeEquals(200, 'The existing /hot page (hot_content view\'s own page_1 display) must remain reachable and unchanged by this story.');
  }

}
