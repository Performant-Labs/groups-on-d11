<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Kernel;

use Drupal\do_chrome\HelpText;
use Drupal\KernelTests\KernelTestBase;

/**
 * #120 SC-1 Persona Switcher — the 4 new append-only `persona.*` HelpText
 * keys (brief.md AC: "HelpText append-only 4 new persona.* keys";
 * brief-amendments.md Amendment 7: values trimmed to <= 140 chars so they
 * fit cleanly in a native `<option title=...>` attribute).
 *
 * Lives under do_showcase's Kernel tests (not do_chrome's) because this
 * story is the one ADDING these 4 keys — mirrors the "each B-story tests its
 * own append" convention already established by HelpText.php's own doc
 * comment ("No B-story edits another's entry").
 *
 * RED reason: none of the 4 `persona.*` keys exist in HelpText::all() yet —
 * `HelpText::get('persona.anonymous')` etc. return '' (the documented
 * unknown-key fallback), so every assertion below fails on an empty string,
 * not a malformed one.
 *
 * @group do_showcase
 * @group do_chrome
 */
final class HelpTextPersonaKeysTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The 4 required keys (brief-amendments.md: `persona.anonymous`,
   * `persona.elena`, `persona.maria`, `persona.moderator`).
   */
  private const EXPECTED_KEYS = [
    'persona.anonymous',
    'persona.elena',
    'persona.maria',
    'persona.moderator',
  ];

  /**
   * All 4 `persona.*` keys are present in `HelpText::all()`.
   */
  public function testAllFourPersonaKeysPresent(): void {
    $all = HelpText::all();
    foreach (self::EXPECTED_KEYS as $key) {
      $this->assertArrayHasKey($key, $all, sprintf('HelpText::all() must contain the "%s" key.', $key));
    }
  }

  /**
   * Each `persona.*` value is non-empty, plain text (no `<`/`>` — matches
   * HelpText's own "keep values plain text — allowHTML disabled" rule), and
   * <= 140 chars (Amendment 7 — must fit a native `<option title=...>`
   * attribute cleanly).
   */
  public function testEachPersonaValueIsPlainTextAndUnder140Chars(): void {
    foreach (self::EXPECTED_KEYS as $key) {
      $value = HelpText::get($key);
      $this->assertNotSame('', $value, sprintf('"%s" must have non-empty copy.', $key));
      $this->assertStringNotContainsString('<', $value, sprintf('"%s" must be plain text (no "<").', $key));
      $this->assertStringNotContainsString('>', $value, sprintf('"%s" must be plain text (no ">").', $key));
      $this->assertLessThanOrEqual(140, strlen($value), sprintf('"%s" must be <= 140 chars to fit a native title= attribute; got %d.', $key, strlen($value)));
    }
  }

}
