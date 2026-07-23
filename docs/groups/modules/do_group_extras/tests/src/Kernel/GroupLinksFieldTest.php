<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_extras\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for #140 MC-1 "Links & Resources" (field_group_links).
 *
 * Issue #140 (epic #137), brief acceptance criteria:
 *  - `field_group_links` storage exists on the `group` entity type as a core
 *    `link` field with unlimited cardinality (title + URL per delta).
 *  - `field_group_links` is instanced on the `community_group` bundle with
 *    label "Links & Resources", not required.
 *  - The group Full display (`group.community_group.default` view display)
 *    exposes the field (non-hidden component).
 *  - The group edit/add form display exposes the field with a link widget
 *    (non-hidden component).
 *  - Rendering a group with external links produces an `<a>` with
 *    `rel="noopener"` (or "noopener noreferrer") and `target="_blank"` —
 *    asserted against OBSERVABLE RENDERED HTML, not formatter config shape,
 *    per A's warn #5 (handoff-A-plan.md): F may satisfy this via the core
 *    `link` formatter's `rel`/`target` settings OR a preprocess_field
 *    fallback — the mechanism is F's choice, only the output is pinned here.
 *  - A group with an internal link (`internal:/node/1`) renders an `<a>`
 *    carrying the link's title text (internal links are not forced to
 *    `target="_blank"`/`rel="noopener"` — those are external-link-only).
 *  - Empty state: a group with NO links set renders NEITHER a "Links &
 *    Resources" text label NOR an empty field wrapper (`<h2>`/`<label>`) —
 *    per A's warn #6, this holds "by construction" if the field's own
 *    `label: above` setting is what produces the heading (Drupal suppresses
 *    the whole field wrapper, including the label, when the field has zero
 *    deltas) — this test proves that observable behavior regardless of
 *    mechanism.
 *
 * None of `field_group_links` config, the view/form display components, or
 * any rendering support exists yet, so every test here is expected to
 * ERROR/FAIL until F lands the config + (if needed) the preprocess fallback.
 *
 * @group do_group_extras
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class GroupLinksFieldTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * do_group_extras (module under test) + link/field/text, on top of the
   * group/gnode/node base stack (mirrors GroupExtrasBehaviorTest's pattern).
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
    'text',
    'link',
    'user',
    'do_group_extras',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['field']);
  }

  /**
   * `field_group_links` storage exists as a `link` field, cardinality -1.
   */
  public function testStorageExists(): void {
    $storage = FieldStorageConfig::loadByName('group', 'field_group_links');

    $this->assertNotNull($storage, 'field_group_links storage config exists on the group entity type.');
    $this->assertSame('link', $storage->getType(), 'field_group_links is a core link field.');
    $this->assertSame(-1, $storage->getCardinality(), 'field_group_links allows unlimited values.');
  }

  /**
   * `field_group_links` is instanced on `community_group` as a non-required,
   * "Links & Resources"-labeled field.
   */
  public function testInstanceExists(): void {
    $instance = FieldConfig::loadByName('group', static::GROUP_TYPE_ID, 'field_group_links');

    $this->assertNotNull($instance, 'field_group_links is instanced on the community_group bundle.');
    $this->assertSame('Links & Resources', $instance->getLabel(), 'The field label is "Links & Resources".');
    $this->assertFalse($instance->isRequired(), 'field_group_links is not required at the field level.');
  }

  /**
   * The group Full display exposes field_group_links (non-hidden component).
   */
  public function testFullDisplayShowsField(): void {
    $display = EntityViewDisplay::load('group.' . static::GROUP_TYPE_ID . '.default');

    $this->assertNotNull($display, 'The group community_group "default" (Full) view display exists.');
    $component = $display->getComponent('field_group_links');
    $this->assertNotNull($component, 'field_group_links has a non-hidden component on the Full display.');
  }

  /**
   * The group add/edit form display exposes field_group_links with a link
   * widget (non-hidden component).
   */
  public function testFormDisplayShowsField(): void {
    $display = EntityFormDisplay::load('group.' . static::GROUP_TYPE_ID . '.default');

    $this->assertNotNull($display, 'The group community_group "default" form display exists.');
    $component = $display->getComponent('field_group_links');
    $this->assertNotNull($component, 'field_group_links has a non-hidden component on the form display.');
    $this->assertSame('link_default', $component['type'] ?? NULL, 'field_group_links uses the link_default widget.');
  }

  /**
   * An external link renders with rel="noopener" (or "noopener noreferrer")
   * and target="_blank" on its <a> tag — observable HTML, mechanism-agnostic.
   */
  public function testRendersExternalLinkWithRelNoopener(): void {
    $group = $this->createGroup([
      'field_group_links' => [
        [
          'uri' => 'https://external.example.com',
          'title' => 'External Site',
        ],
      ],
    ]);

    $html = $this->renderGroupFull($group);

    $this->assertMatchesRegularExpression(
      '#<a[^>]+href="https://external\.example\.com"[^>]*rel="noopener[^"]*"[^>]*>#',
      $html,
      'The external link anchor carries rel="noopener" (optionally "noopener noreferrer").',
    );
    $this->assertMatchesRegularExpression(
      '#<a[^>]+href="https://external\.example\.com"[^>]*target="_blank"[^>]*>#',
      $html,
      'The external link anchor carries target="_blank".',
    );
  }

  /**
   * An internal link renders an <a> carrying the link's title text.
   */
  public function testInternalLinkRendered(): void {
    $group = $this->createGroup([
      'field_group_links' => [
        [
          'uri' => 'internal:/node/1',
          'title' => 'Internal Page',
        ],
      ],
    ]);

    $html = $this->renderGroupFull($group);

    $this->assertStringContainsString('Internal Page', $html, 'The internal link title text is rendered.');
    $this->assertMatchesRegularExpression('#<a[^>]*>\s*Internal Page\s*</a>#', $html, 'The internal link title is wrapped in a real <a> tag.');
  }

  /**
   * Empty state: a group with no links renders NO "Links & Resources" text
   * and no bare field wrapper (<h2>/<label>) for the field.
   */
  public function testEmptyStateRendersNothing(): void {
    // Deliberately does NOT set field_group_links — this also covers the
    // case (true today) where the field does not exist yet: a group with no
    // links configured must never expose "Links & Resources" markup.
    $group = $this->createGroup();

    $html = $this->renderGroupFull($group);

    $this->assertStringNotContainsString('Links & Resources', $html, 'No "Links & Resources" label/heading renders when the field is empty.');
    $this->assertDoesNotMatchRegularExpression('#<h2[^>]*>\s*Links#i', $html, 'No <h2> heading for the empty field renders.');
    $this->assertDoesNotMatchRegularExpression('#<label[^>]*>\s*Links#i', $html, 'No <label> for the empty field renders.');
  }

  /**
   * Renders a group's Full display and returns the HTML string.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to render.
   *
   * @return string
   *   The rendered markup.
   */
  private function renderGroupFull(\Drupal\group\Entity\GroupInterface $group): string {
    $view_builder = $this->entityTypeManager->getViewBuilder('group');
    $build = $view_builder->view($group, 'default');
    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = $this->container->get('renderer');
    return (string) $renderer->renderRoot($build);
  }

}
