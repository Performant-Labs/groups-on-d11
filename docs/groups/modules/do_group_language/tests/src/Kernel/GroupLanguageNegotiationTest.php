<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_language\Kernel;

use Drupal\do_group_language\Plugin\LanguageNegotiation\LanguageNegotiationGroup;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Behavioral coverage for do_group_language's negotiation plugin.
 *
 * Issue #42 (Wave C / C4), epic #31. Exercises
 * {@see \Drupal\do_group_language\Plugin\LanguageNegotiation\LanguageNegotiationGroup::getLangcode()}
 * directly, driving it with real Request paths against a real saved group:
 *
 * - A `/group/{gid}` (or `/group/{gid}/...`) path whose group has
 *   `field_group_language` set returns that langcode.
 * - The empty/undefined sentinels (`und`, `zxx`, empty string) are skipped —
 *   the plugin returns NULL so a later negotiator decides.
 * - A path that is not group-scoped, a missing group, and a group without the
 *   field / with an empty field all return NULL.
 *
 * The plugin loads the group through \Drupal::entityTypeManager() by the id
 * captured from the path regex, so the field only needs to exist on the group
 * entity — no request-stack or language-manager wiring is required to assert
 * the returned langcode.
 *
 * @group do_group_language
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class GroupLanguageNegotiationTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * do_group_language (module under test) + language (its hard dependency) +
   * field, on top of the group/gnode/node base stack.
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
    'language',
    'do_group_language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Attach the string field the plugin reads. It stores a raw langcode
    // string (the plugin returns ->value verbatim), so a plain string field.
    FieldStorageConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'label' => 'Group language',
    ])->save();
  }

  /**
   * Runs the plugin against a request for the given path.
   */
  private function negotiate(string $path): ?string {
    $plugin = new LanguageNegotiationGroup();
    $plugin->setLanguageManager($this->container->get('language_manager'));
    return $plugin->getLangcode(Request::create($path));
  }

  /**
   * A group with field_group_language set resolves that langcode for its path.
   */
  public function testReturnsConfiguredLangcodeForGroupPath(): void {
    $group = $this->createGroup(['field_group_language' => 'fr']);

    $this->assertSame('fr', $this->negotiate('/group/' . $group->id()));
    // Also holds for a deeper sub-path under the group.
    $this->assertSame('fr', $this->negotiate('/group/' . $group->id() . '/nodes'));
  }

  /**
   * The und / zxx / empty sentinels are skipped (plugin defers, returns NULL).
   */
  public function testSkipsUndefinedAndEmptyLangcodes(): void {
    foreach (['und', 'zxx', ''] as $sentinel) {
      $group = $this->createGroup(['field_group_language' => $sentinel]);
      $this->assertNull(
        $this->negotiate('/group/' . $group->id()),
        sprintf('Sentinel langcode %s is skipped.', var_export($sentinel, TRUE)),
      );
    }
  }

  /**
   * A group whose field_group_language is unset resolves to NULL.
   */
  public function testReturnsNullWhenFieldEmpty(): void {
    $group = $this->createGroup();

    $this->assertNull($this->negotiate('/group/' . $group->id()));
  }

  /**
   * A non-group path is not matched — the plugin returns NULL.
   */
  public function testReturnsNullForNonGroupPath(): void {
    $this->createGroup(['field_group_language' => 'fr']);

    $this->assertNull($this->negotiate('/node/1'));
    $this->assertNull($this->negotiate('/'));
    // A path that merely contains the segment but is not /group/{digits}.
    $this->assertNull($this->negotiate('/mygroup/1'));
  }

  /**
   * A path referencing a non-existent group id resolves to NULL.
   */
  public function testReturnsNullForMissingGroup(): void {
    $this->assertNull($this->negotiate('/group/999999'));
  }

  /**
   * A NULL request (no request context) resolves to NULL, not an error.
   */
  public function testReturnsNullForNullRequest(): void {
    $plugin = new LanguageNegotiationGroup();
    $plugin->setLanguageManager($this->container->get('language_manager'));

    $this->assertNull($plugin->getLangcode(NULL));
  }

}
