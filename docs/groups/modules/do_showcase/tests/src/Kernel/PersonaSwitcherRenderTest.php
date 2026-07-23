<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * #120 SC-1 Persona Switcher — `do_showcase.persona_switcher` render-array
 * contract (brief-amendments.md Amendment 6: `#cache[contexts] => ['user']`;
 * wireframe.md §1: native `<select>` with 4 `<option>` elements, current
 * selection pre-selected from session state).
 *
 * RED reason: `do_showcase.persona_switcher` is not a registered service
 * (do_showcase.services.yml has no such key) and no `PersonaSwitcher` class
 * exists at `Drupal\do_showcase\Persona\PersonaSwitcher` — every test fails
 * on "Service ... not found", not a wrong render-array shape.
 *
 * @group do_showcase
 */
final class PersonaSwitcherRenderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'do_showcase'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
  }

  /**
   * The built render array declares `#cache['contexts']` including `user`
   * (Amendment 6 — a missing context here would leak a stale-persona banner/
   * widget between sessions).
   */
  public function testBuildDeclaresUserCacheContext(): void {
    /** @var object $switcher */
    $switcher = \Drupal::service('do_showcase.persona_switcher');
    $build = $switcher->build();

    $this->assertArrayHasKey('#cache', $build, 'Render array must declare #cache.');
    $this->assertArrayHasKey('contexts', $build['#cache'], 'Render array #cache must declare contexts.');
    $this->assertContains('user', $build['#cache']['contexts'], 'The persona switcher render array must vary by the "user" cache context.');
  }

  /**
   * The build contains a `<select>` with exactly 4 `<option>` entries, each
   * carrying a non-empty `title` attribute (wireframe.md §1: native
   * per-option hover text, the closest per-option tooltip mechanism a real
   * `<select>` supports).
   */
  public function testBuildContainsFourOptionsWithNonEmptyTitles(): void {
    /** @var object $switcher */
    $switcher = \Drupal::service('do_showcase.persona_switcher');
    $build = $switcher->build();

    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('<select', $html, 'Rendered markup must contain a <select> element.');
    $option_count = substr_count($html, '<option');
    $this->assertSame(4, $option_count, 'Rendered markup must contain exactly 4 <option> elements.');

    // Every <option ...> tag must carry a non-empty title="..." attribute.
    preg_match_all('/<option\b[^>]*>/', $html, $matches);
    $this->assertCount(4, $matches[0]);
    foreach ($matches[0] as $option_tag) {
      $this->assertMatchesRegularExpression('/title="[^"]+"/', $option_tag, sprintf('Every <option> must carry a non-empty title attribute; got: %s', $option_tag));
    }
  }

  /**
   * When the current session is anonymous, the `anonymous` option is the
   * one marked `selected` (server-set from real session state, per
   * wireframe.md §1: "Current persona is pre-selected ... a page refresh
   * always shows the true current state").
   */
  public function testAnonymousSessionSelectsAnonymousOption(): void {
    /** @var object $switcher */
    $switcher = \Drupal::service('do_showcase.persona_switcher');
    $build = $switcher->build();
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);

    $this->assertMatchesRegularExpression(
      '/<option\b[^>]*value="anonymous"[^>]*selected/',
      $html,
      'With an anonymous session, the "anonymous" <option> must carry the selected attribute.'
    );
  }

  /**
   * When the current session IS Elena Garcia's account (uname
   * `elena_garcia`), the "elena-garcia" option is the one marked selected —
   * proves current-selection is derived from real session state, not a
   * hardcoded default.
   */
  public function testAuthenticatedPersonaSessionSelectsMatchingOption(): void {
    $elena = User::create(['name' => 'elena_garcia', 'status' => 1]);
    $elena->save();
    $this->container->get('current_user')->setAccount($elena);

    /** @var object $switcher */
    $switcher = \Drupal::service('do_showcase.persona_switcher');
    $build = $switcher->build();
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);

    $this->assertMatchesRegularExpression(
      '/<option\b[^>]*value="elena-garcia"[^>]*selected/',
      $html,
      'With Elena\'s session active, the "elena-garcia" <option> must carry the selected attribute.'
    );
  }

}
