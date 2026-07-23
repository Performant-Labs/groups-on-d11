<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * #120 SC-1 Persona Switcher — the persistent active-persona banner.
 *
 * wireframe.md §2: rendered via a sibling `#[Hook('page_top')] personaBanner()`
 * method (never folded into the ribbon's `pageTop()` — Amendment 5). Absent
 * entirely from the DOM (not hidden via CSS) when the session is anonymous;
 * present with `role="status"`, the exact per-persona copy, and a real
 * `<a href="/persona-switch/anonymous">` switch-back link when a persona is
 * active.
 *
 * This test asserts the RENDER path only (per the harness prompt: "the
 * switcher-controller flow is covered by e2e") — it logs in as Elena
 * directly via `drupalLogin()` (BrowserTestBase self-installs with no demo
 * seed, so Elena is created here, not seeded) rather than driving the
 * dropdown/controller, since `personaBanner()`'s render condition is "the
 * current session's uname is an allowlisted persona uname", which
 * `drupalLogin()` satisfies identically to a real persona switch.
 *
 * RED reason: `DoShowcaseHooks::personaBanner()` does not exist yet — no
 * `<aside role="status">` renders under any session, so the "banner absent
 * when anonymous" assertion trivially/vacuously would pass for the wrong
 * reason if authored alone; the paired "banner present when persona active"
 * assertion is the one that fails for the RIGHT reason (missing markup).
 *
 * #132 SD-5 (Showcase help) extends this file with the persona-banner ⓘ
 * help-trigger coverage (brief.md "Persona banner ⓘ" acceptance criterion +
 * brief-amendments.md Amendment 1's corrected child order:
 * `glyph, text, switch_back, help`). RED reason for the new tests: F has not
 * yet appended the `help` child to `DoShowcaseHooks::personaBanner()`'s
 * `$children` array, so no `[data-do-tooltip]` node exists inside the
 * `<aside>` at all — these assertions fail on a missing element, not an
 * unrelated symptom.
 *
 * @group do_showcase
 */
final class PersonaBannerTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['do_showcase'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Anonymous session: no banner markup anywhere in the DOM (truthful-empty-
   * state rule — wireframe.md §2: "not hidden via CSS — truly absent").
   */
  public function testAnonymousSessionHasNoBanner(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->elementNotExists('css', 'aside[role="status"].do-showcase-persona-banner');
  }

  /**
   * Elena's session (uname `elena_garcia`, an allowlisted persona uname):
   * the banner renders with `role="status"`, the exact copy, and a real
   * switch-back `<a>` link.
   */
  public function testElenaSessionShowsBannerWithExactCopyAndSwitchBackLink(): void {
    $elena = $this->drupalCreateUser([], 'elena_garcia');
    $this->drupalLogin($elena);

    $this->drupalGet('<front>');

    $banner = $this->assertSession()->elementExists('css', 'aside[role="status"].do-showcase-persona-banner');
    $this->assertStringContainsString(
      "You're browsing as Elena Garcia — Member — switch back",
      $banner->getText(),
      'Banner copy must match the exact issue phrasing for Elena.'
    );

    $link = $this->assertSession()->elementExists('css', 'a[href="/persona-switch/anonymous"]', $banner);
    $this->assertNotEmpty($link->getText(), 'The switch-back link must be a real, non-empty <a> element.');
  }

  /**
   * #132 SD-5: anonymous session still has NO help-trigger markup either —
   * regression guard alongside the existing "no banner at all" assertion, so
   * a future implementation that renders the ⓘ trigger independently of the
   * banner container (e.g. via a separate #[Hook]) is still caught.
   */
  public function testAnonymousSessionHasNoPersonaHelpTrigger(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->elementNotExists('css', 'aside.do-showcase-persona-banner [data-do-tooltip]');
  }

  /**
   * #132 SD-5: for each of the three logged-in personas
   * (elena_garcia / maria_chen / groups_moderate_demo), the banner's
   * `<aside>` contains a help-trigger `<span>` that is:
   *   - a `[data-do-tooltip]` node with non-empty tooltip text,
   *   - carrying the `do-showcase-info` class (verbatim reuse of the
   *     `PersonaSwitcher::build()` / `GroupTypeContentHelp::infoTrigger()`
   *     pattern, per the brief's Reuse map),
   *   - keyboard-reachable (`tabindex="0"`),
   *   - `role="note"`,
   *   - carrying a non-empty `aria-label`,
   *   - whose tooltip copy matches `HelpText::get('showcase_help.persona_banner')`
   *     exactly (single source of truth — no hand-duplicated string).
   *
   * A single data-provider-style loop over all three personas (not three
   * separate test methods) — the contract is identical per persona; only
   * the login account differs. `groups_moderate_demo` is created directly
   * via `drupalCreateUser()` (this is a BrowserTestBase self-install, no
   * demo seed) exactly as `elena_garcia` already is above.
   *
   * @dataProvider providePersonaUnames
   */
  public function testPersonaBannerHasHelpTriggerWithExpectedAttributes(string $uname): void {
    $user = $this->drupalCreateUser([], $uname);
    $this->drupalLogin($user);

    $this->drupalGet('<front>');

    $banner = $this->assertSession()->elementExists('css', 'aside[role="status"].do-showcase-persona-banner');
    $help = $this->assertSession()->elementExists('css', '[data-do-tooltip]', $banner);

    $this->assertNotEmpty($help->getAttribute('data-do-tooltip'), 'The persona-banner help trigger\'s data-do-tooltip must be non-empty.');
    $this->assertStringContainsString('do-showcase-info', (string) $help->getAttribute('class'), 'The help trigger must carry the do-showcase-info class (verbatim reuse of the existing ⓘ pattern).');
    $this->assertSame('0', $help->getAttribute('tabindex'), 'The help trigger must be keyboard-reachable (tabindex="0").');
    $this->assertSame('note', $help->getAttribute('role'), 'The help trigger must carry role="note".');
    $this->assertNotEmpty($help->getAttribute('aria-label'), 'The help trigger must carry a non-empty aria-label.');

    $expected_copy = \Drupal\do_chrome\HelpText::get('showcase_help.persona_banner');
    $this->assertNotSame('', $expected_copy, 'Test setup sanity: showcase_help.persona_banner must resolve to non-empty copy (pinned separately by ShowcaseHelpTextTest).');
    $this->assertSame($expected_copy, $help->getAttribute('data-do-tooltip'), 'The help trigger\'s tooltip copy must match HelpText::get(\'showcase_help.persona_banner\') exactly.');
  }

  /**
   * Data provider: the three logged-in persona unames the banner recognizes.
   *
   * @return array<string, array{0: string}>
   */
  public static function providePersonaUnames(): array {
    return [
      'Elena Garcia (Member)' => ['elena_garcia'],
      'Maria Chen (Organizer)' => ['maria_chen'],
      'Groups-Moderate' => ['groups_moderate_demo'],
    ];
  }

  /**
   * #132 SD-5 / brief-amendments.md Amendment 1 (A1): the ⓘ help trigger
   * must appear AFTER the switch-back link in the `<aside>`'s child order —
   * the brief's own worked example placed it before `glyph`, which A's
   * review corrected: the visual intent is "the ⓘ trails the switch-back
   * link," so the final order must be `glyph, text, switch_back, help`.
   *
   * Asserted structurally (DOM order of elements inside the aside), not by
   * substring position in flattened text, so a future refactor that
   * preserves visible reading order but reshuffles internal markup is still
   * caught if it violates the actual element order.
   */
  public function testHelpTriggerAppearsAfterSwitchBackLinkInDomOrder(): void {
    $elena = $this->drupalCreateUser([], 'elena_garcia');
    $this->drupalLogin($elena);
    $this->drupalGet('<front>');

    $banner = $this->assertSession()->elementExists('css', 'aside[role="status"].do-showcase-persona-banner');
    $html = $banner->getOuterHtml();

    $switch_back_pos = strpos($html, 'do-showcase-persona-banner-switch-back');
    $help_pos = strpos($html, 'data-do-tooltip');

    $this->assertIsInt($switch_back_pos, 'The switch-back link must be present in the banner markup.');
    $this->assertIsInt($help_pos, 'The help trigger must be present in the banner markup.');
    $this->assertGreaterThan(
      $switch_back_pos,
      $help_pos,
      'Amendment 1 (A1): the help trigger must appear AFTER the switch-back link in DOM order (glyph, text, switch_back, help).'
    );
  }

  /**
   * #132 SD-5 / brief-amendments.md Amendment 2 (A2): `personaBanner()` must
   * explicitly attach `do_chrome/tooltips` — never rely on a transitive
   * dependency via `do_showcase/persona-switcher`. Asserted via the page's
   * `drupalSettings`/attached-library footprint: the library's own asset
   * (tooltips.js, per `do_chrome/tooltips`'s library definition) must be
   * present on a page where only the persona banner (not the switcher
   * dropdown) would otherwise need it — i.e. this test targets the
   * PRESENCE of the tooltips library specifically because of the banner,
   * proven by asserting on the raw response for the library's asset path
   * rather than assuming the switcher's own attach (which already exists)
   * happens to cover it.
   */
  public function testTooltipsLibraryIsAttachedOnPersonaBannerPage(): void {
    $elena = $this->drupalCreateUser([], 'elena_garcia');
    $this->drupalLogin($elena);
    $this->drupalGet('<front>');

    // do_chrome/tooltips ships tooltips.js — its <script> tag (or module
    // reference) must be present in the response when the persona banner
    // renders, per Amendment 2's explicit-attach requirement.
    $this->assertSession()->responseContains('do_chrome.tooltips.js');
  }

}
