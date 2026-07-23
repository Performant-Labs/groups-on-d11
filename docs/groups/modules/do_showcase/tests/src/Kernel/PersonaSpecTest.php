<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Kernel;

use Drupal\do_showcase\ShowcaseCatalog;
use Drupal\KernelTests\KernelTestBase;

/**
 * #120 SC-1 Persona Switcher — extended `ShowcaseCatalog::personas()` shape.
 *
 * Brief-amendment A2 (handoff-A-plan.md finding #2, resolved in
 * brief-amendments.md Amendment 2): `PersonaRegistry` is REMOVED from the
 * plan; `ShowcaseCatalog::personas()` is EXTENDED IN PLACE with two new
 * fields per persona entry: `uname` (NULL for anonymous; the seeded account
 * name for the other three) and `tooltip_key` (the `persona.*` HelpText key
 * each option's native `title=` and the wrapper tooltip read from). A new
 * helper `ShowcaseCatalog::personaSpec(string $id): ?array` returns one
 * persona by id (used by PersonaAccessCheck + PersonaSwitcher + the switch
 * controller so there is exactly one lookup site).
 *
 * RED reason: today `personas()` returns only `{id, name, description}`
 * (ShowcaseCatalog.php lines 97-120) — no `uname`/`tooltip_key` keys, and no
 * `personaSpec()` method exists at all. Every assertion below fails because
 * the array key is missing / the method does not exist, not because the
 * VALUES are wrong.
 *
 * @group do_showcase
 */
final class PersonaSpecTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * The four expected persona ids, in the exact display order the brief and
   * wireframe both name them.
   */
  private const EXPECTED_ORDER = [
    'anonymous',
    'elena-garcia',
    'maria-chen',
    'moderator',
  ];

  /**
   * The expected `uname` per persona id (brief-amendments.md Amendment 2).
   */
  private const EXPECTED_UNAME = [
    'anonymous' => NULL,
    'elena-garcia' => 'elena_garcia',
    'maria-chen' => 'maria_chen',
    'moderator' => 'groups_moderate_demo',
  ];

  /**
   * The expected `tooltip_key` per persona id (brief-amendments.md Amendment 2).
   */
  private const EXPECTED_TOOLTIP_KEY = [
    'anonymous' => 'persona.anonymous',
    'elena-garcia' => 'persona.elena',
    'maria-chen' => 'persona.maria',
    'moderator' => 'persona.moderator',
  ];

  /**
   * `personas()` returns exactly the 4 expected ids, IN ORDER.
   */
  public function testPersonasReturnsFourIdsInOrder(): void {
    $catalog = new ShowcaseCatalog();
    $personas = $catalog->personas();

    $this->assertCount(4, $personas, 'Exactly four public personas.');
    $ids = array_column($personas, 'id');
    $this->assertSame(self::EXPECTED_ORDER, $ids, 'Persona ids must appear in the exact order: anonymous, elena-garcia, maria-chen, moderator.');
  }

  /**
   * Every persona entry carries a non-empty id, name, description.
   */
  public function testEveryPersonaHasNonEmptyIdNameDescription(): void {
    $catalog = new ShowcaseCatalog();
    foreach ($catalog->personas() as $persona) {
      $this->assertNotEmpty($persona['id'] ?? NULL, 'Persona id must be non-empty.');
      $this->assertNotEmpty($persona['name'] ?? NULL, sprintf('Persona "%s" must have a non-empty name.', $persona['id'] ?? '?'));
      $this->assertNotEmpty((string) ($persona['description'] ?? ''), sprintf('Persona "%s" must have a non-empty description.', $persona['id'] ?? '?'));
    }
  }

  /**
   * Every persona entry carries the new `uname` field with the exact
   * expected value — NULL for anonymous, the seeded account name for the
   * other three (Amendment 2).
   *
   * T's fix (Phase 6, Bug A): the original `EXPECTED_UNAME[$id] ?? '__unexpected__'`
   * used PHP's `??`, which treats an array key whose VALUE is NULL as if the
   * key were absent — since `EXPECTED_UNAME['anonymous']` is itself NULL (the
   * correct expected value per Amendment 2), the expression always fell
   * through to the '__unexpected__' fallback for the anonymous case,
   * regardless of what `personas()` actually returned. Fixed by checking
   * `array_key_exists()` first (still guards against a genuinely unexpected
   * persona id appearing), then indexing directly so a real NULL expected
   * value is preserved.
   */
  public function testEveryPersonaHasExpectedUname(): void {
    $catalog = new ShowcaseCatalog();
    foreach ($catalog->personas() as $persona) {
      $this->assertArrayHasKey('uname', $persona, sprintf('Persona "%s" must carry a "uname" key.', $persona['id'] ?? '?'));
      $this->assertArrayHasKey($persona['id'], self::EXPECTED_UNAME, sprintf('Persona id "%s" is not one of the expected personas.', $persona['id'] ?? '?'));
      $expected = self::EXPECTED_UNAME[$persona['id']];
      $this->assertSame($expected, $persona['uname'], sprintf('Persona "%s" uname must be %s.', $persona['id'], var_export($expected, TRUE)));
    }
  }

  /**
   * Every persona entry carries the new `tooltip_key` field with the exact
   * expected `persona.*` key (Amendment 2).
   */
  public function testEveryPersonaHasExpectedTooltipKey(): void {
    $catalog = new ShowcaseCatalog();
    foreach ($catalog->personas() as $persona) {
      $this->assertArrayHasKey('tooltip_key', $persona, sprintf('Persona "%s" must carry a "tooltip_key" key.', $persona['id'] ?? '?'));
      $expected = self::EXPECTED_TOOLTIP_KEY[$persona['id']] ?? '__unexpected__';
      $this->assertSame($expected, $persona['tooltip_key'], sprintf('Persona "%s" tooltip_key must be "%s".', $persona['id'], $expected));
    }
  }

  /**
   * `personaSpec('maria-chen')` returns the Maria row (single-lookup helper,
   * Amendment 2).
   */
  public function testPersonaSpecReturnsMariaRow(): void {
    $catalog = new ShowcaseCatalog();
    $spec = $catalog->personaSpec('maria-chen');

    $this->assertIsArray($spec, 'personaSpec("maria-chen") must return an array, not NULL.');
    $this->assertSame('maria-chen', $spec['id']);
    $this->assertSame('maria_chen', $spec['uname']);
    $this->assertSame('persona.maria', $spec['tooltip_key']);
  }

  /**
   * `personaSpec('unknown')` returns NULL — never throws, never a partial
   * array (the access-check + controller both branch on a NULL return to
   * deny/404 an unrecognized persona id).
   */
  public function testPersonaSpecReturnsNullForUnknownId(): void {
    $catalog = new ShowcaseCatalog();
    $this->assertNull($catalog->personaSpec('unknown'), 'personaSpec() must return NULL for an unrecognized persona id.');
  }

}
