<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Unit;

use Drupal\do_chrome\HelpText;
use PHPUnit\Framework\TestCase;

/**
 * ST-8 (#130): pins both HelpText keys the stream-model comparison touches.
 *
 * Two DISTINCT keys, different namespaces, same underlying comparison
 * (wireframe.md "Open questions for approval" #1 / brief.md Amendment 1):
 *
 * - `showcase.switcher.stream.model` — NEW key, the switcher's own ⓘ
 *   tooltip on `/stream` (`VariantSwitcher::build()`'s
 *   `HelpText::get('showcase.switcher.<instance_id>')` lookup for instance
 *   id `stream.model`). D's approved copy (handoff-D.md): names what each
 *   view includes, that Content view is leaner, and that it is coming soon.
 * - `showcase_help.stream-model` — PRE-EXISTING key (the `/showcase` tour
 *   page's own per-entry orientation tooltip for the `stream-model` catalog
 *   card, #132/SD-5 namespace). Amendment 1: its stale copy ("One combined
 *   activity stream vs. separate streams per content type...") carries the
 *   SAME wrong framing as the old decision_sentence and must be corrected
 *   in step, to match the corrected `ShowcaseCatalog` decision_sentence
 *   (`ShowcaseCatalogTest::testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence`).
 *
 * RED reason (Phase 4): `showcase.switcher.stream.model` does not exist yet
 * in `HelpText::all()` (resolves to `''`, the unknown-key default); the
 * pre-existing `showcase_help.stream-model` key still carries the stale
 * copy. Both assertion sets fail until F appends/updates the keys.
 *
 * @coversDefaultClass \Drupal\do_chrome\HelpText
 */
final class StreamModelHelpTextTest extends TestCase {

  /**
   * The NEW switcher-tooltip key exists, is plain text, and matches D's
   * approved copy (handoff-D.md): names Activity view's row types, Content
   * view's leaner shape, and "(coming soon)".
   *
   * @covers ::get
   */
  public function testSwitcherStreamModelTooltipCopyIsPresentAndMatchesApprovedCopy(): void {
    $copy = HelpText::get('showcase.switcher.stream.model');
    $this->assertNotSame('', $copy, 'The showcase.switcher.stream.model tooltip copy must exist (append-only, per brief.md scope item #7).');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');

    // (a) what content is included in each view — Activity view's row types
    // named explicitly.
    foreach (['posts', 'comments', 'flags', 'pins', 'membership'] as $term) {
      $this->assertStringContainsString($term, $copy, "Copy must name Activity view's '$term' row type/content.");
    }

    // (b) that the content-only model is leaner.
    $this->assertMatchesRegularExpression('/\blean(er)?\b/i', $copy, 'Copy must describe Content view as the leaner model.');

    // (c) that Content view is coming soon.
    $this->assertMatchesRegularExpression('/coming soon/i', $copy, 'Copy must qualify Content view as "(coming soon)".');

    $this->assertArrayHasKey('showcase.switcher.stream.model', HelpText::all(), 'The key must be a literal entry in HelpText::all() (append-only contract).');
  }

  /**
   * The PRE-EXISTING `/showcase` tour orientation-tooltip key is corrected
   * to match the new comparison framing — no longer the stale "combined
   * stream vs. per-content-type streams" copy.
   *
   * @covers ::get
   */
  public function testShowcaseHelpStreamModelCopyIsUpdatedToMatchNewFraming(): void {
    $copy = HelpText::get('showcase_help.stream-model');
    $this->assertNotSame('', $copy, 'The showcase_help.stream-model tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');

    $this->assertStringNotContainsString(
      'per-content-type',
      $copy,
      'The stale "combined activity stream vs. per-content-type streams" framing (brief.md Amendment 1) must no longer appear — this describes a comparison this story does not build.'
    );

    // Must reflect D's approved decision_sentence framing: node-content
    // model vs. activity-log model (handoff-D.md).
    $this->assertMatchesRegularExpression('/node-content model/i', $copy, 'Corrected copy must name the node-content model half of the comparison.');
    $this->assertMatchesRegularExpression('/activity-log model/i', $copy, 'Corrected copy must name the activity-log model half of the comparison.');
  }

}
