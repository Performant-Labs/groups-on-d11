<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Kernel;

use Drupal\do_chrome\HelpText;
use Drupal\do_chrome\Hook\PageHelp;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * #131 (SD-4): Streams help — new `stream.*` element-tooltip keys, the
 * PageHelp route-map bugfix for /following, and the `page.stream` copy
 * enrichment.
 *
 * This is a companion to the (not-yet-existing) HelpTextPageKeysTest — no
 * such test exists in the base yet (verified: no HelpTextPageKeysTest.php
 * under do_chrome/tests), so this file does NOT duplicate any existing
 * `page.*` non-empty-resolution assertion; it only pins the DELTA this story
 * introduces:
 *  - 6 new `stream.*` element-tooltip keys (empty state, RSVP chip, 3
 *    activity-row variants, content-vs-activity toggle).
 *  - PageHelp::getRouteMap() must map `view.following_feed.page_1` (the
 *    REAL view id) to `page.following`, and must NOT still carry the stale
 *    `view.following.page_1` key (brief.md / survey.md: base has the wrong
 *    view id, so /following currently gets no page-level (i) at all).
 *  - The LIVE `page.stream` copy must be enriched to be HONEST ABOUT POC
 *    SCORING (brief.md Copy plan: "honest about POC scoring"). NOTE: the
 *    base copy ALREADY contains "site-wide" / "every public group" language
 *    (verified by reading HelpText::all() before authoring this test), so
 *    that half of the Copy-plan sentence is not a valid RED target — this
 *    assertion instead pins the genuinely-missing half: a POC/demo-scoring
 *    honesty phrase, which the base copy does not contain at all.
 *
 * RED reason (today, before F implements): none of the 6 `stream.*` keys
 * exist in HelpText::all() yet (HelpText::get() falls back to the
 * unknown-key '' default), PageHelp::getRouteMap() still contains the stale
 * `view.following.page_1` => `page.following` entry instead of the corrected
 * view id, and the current `page.stream` copy contains no POC/demo-honesty
 * language at all.
 *
 * A lightweight \Drupal\KernelTests\KernelTestBase (not the heavier
 * GroupsKernelTestBase) is used deliberately: HelpText::get()/all() and
 * PageHelp::getRouteMap() are both plain, DI-free static/no-service methods
 * that need no group/node/field scaffolding — only the do_chrome module
 * needs to be installed so the classes autoload under the real module
 * namespace in the assembled `web/modules/custom` tree.
 *
 * @group do_chrome
 */
#[RunTestsInSeparateProcesses]
final class HelpTextStreamKeysTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['do_chrome'];

  /**
   * The 6 new `stream.*` element-tooltip keys this story appends.
   *
   * @return string[]
   */
  private function newStreamKeys(): array {
    return [
      'stream.my_feed.empty',
      'stream.my_feed_events.rsvp_chip',
      'stream.activity_row.social',
      'stream.activity_row.aggregated',
      'stream.activity_row.comment',
      'stream.model_toggle',
    ];
  }

  /**
   * Every new `stream.*` key must be a literal key in HelpText::all() and
   * resolve to non-empty, plain-text copy.
   */
  public function testNewStreamKeysResolveNonEmpty(): void {
    $all = HelpText::all();
    foreach ($this->newStreamKeys() as $key) {
      $this->assertArrayHasKey($key, $all, sprintf('"%s" must be a literal key in HelpText::all() (append-only contract).', $key));
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Tooltip copy for "%s" must exist and resolve non-empty.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled downstream).');
    }
  }

  /**
   * PageHelp::getRouteMap() must map the corrected `view.following_feed.
   * page_1` route to `page.following`, and must NOT still carry the stale
   * `view.following.page_1` key.
   *
   * The real view id is `following_feed` (config
   * views.view.following_feed.yml), not `following` — the base map has the
   * wrong route name, so /following currently gets no page-level ⓘ at all
   * despite SD-1's intent (survey.md "Bug found in base").
   */
  public function testFollowingRouteMapKeyIsCorrected(): void {
    $page_help = new PageHelp($this->container->get('current_route_match'));
    $map = $page_help->getRouteMap();

    $this->assertArrayHasKey(
      'view.following_feed.page_1',
      $map,
      'getRouteMap() must map the REAL /following view route (view.following_feed.page_1) to a HelpText key.'
    );
    $this->assertSame(
      'page.following',
      $map['view.following_feed.page_1'],
      'The corrected route must map to the page.following HelpText key.'
    );
    $this->assertArrayNotHasKey(
      'view.following.page_1',
      $map,
      'The stale, wrong-view-id route key (view.following.page_1) must no longer be present.'
    );
  }

  /**
   * The LIVE `page.stream` copy must be enriched with honest POC-scoring
   * language (brief.md Copy plan: "honest about POC scoring").
   *
   * Lenient substring assertion (delta only) — this does NOT re-assert the
   * "site-wide activity" framing, which is already present in the base copy
   * (verified directly in HelpText.php before authoring this test) and is
   * therefore not a valid RED target. It pins only the genuinely-missing
   * half of the Copy-plan ask: some honest acknowledgement that this is a
   * proof-of-concept / demo, distinguishing it from a claim of a fully
   * engineered production ranking/scope.
   */
  public function testPageStreamCopyIsEnrichedWithHonestPocLanguage(): void {
    $copy = HelpText::get('page.stream');
    $this->assertNotSame('', $copy, 'page.stream copy must exist.');

    $has_poc_honesty_language = (bool) preg_match('/\bPOC\b/i', $copy)
      || (bool) preg_match('/proof.of.concept/i', $copy)
      || (bool) preg_match('/\bdemo\b/i', $copy);

    $this->assertTrue(
      $has_poc_honesty_language,
      'page.stream copy must be enriched with honest POC/demo-scoring language (brief.md Copy plan: "honest about POC scoring"), e.g. containing "POC", "proof-of-concept", or "demo".'
    );
  }

}
