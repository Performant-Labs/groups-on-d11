<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_extras\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for #141 MC-2 "About" section (field_group_about).
 *
 * Issue #141 (epic #137), brief acceptance criteria (AC-1..AC-6):
 *  - `field_group_about` storage exists on the `group` entity type as a core
 *    `text_long` field with cardinality 1, translatable.
 *  - `field_group_about` is instanced on the `community_group` bundle with
 *    label "About", not required, translatable.
 *  - The group Full display (`group.community_group.default` view display)
 *    exposes the field at weight 10 with `label: above` and formatter
 *    `text_default`.
 *  - The group edit/add form display exposes the field with the
 *    `text_textarea` widget (non-hidden component).
 *  - Rendering a group with a formatted About body (`basic_html`) produces
 *    the sanitized rich HTML inside the rendered field wrapper — asserted
 *    against OBSERVABLE RENDERED HTML (`<strong>Hello</strong>` present),
 *    not formatter config shape, per A's warn #5 (handoff-A-plan.md): F may
 *    satisfy this via the core `text_default` formatter or a preprocess
 *    fallback — the mechanism is F's choice, only the output is pinned here.
 *  - Empty state: a group with NO About body set renders NEITHER an "About"
 *    text label/heading NOR a bare field wrapper — covering BOTH empty
 *    shapes per A's warn #4: (a) the field never set on the group at all,
 *    and (b) the field explicitly set to `[value => '', format =>
 *    'basic_html']` (the shape a text_textarea widget could in principle
 *    produce on an empty submit).
 *
 * `basic_html` filter format note (A warn #5): kernel tests do NOT install
 * site FilterFormat config (no `filter.format.basic_html` ships via any
 * listed module's `config/install/` in this fixture), so this test
 * materializes a MINIMAL `basic_html` FilterFormat programmatically in
 * `setUp()`, with `filter_html` allowing at least `<p>` and `<strong>` —
 * enough to prove the sanitized-HTML-survives-rendering behavior without
 * depending on the real site's shipped filter format (which is out of
 * this kernel fixture's reach). `filter` is added to `$modules` for this.
 *
 * `setUp()` builds `field_group_about`'s storage, bundle instance, and both
 * displays PROGRAMMATICALLY, mirroring the exact convention this module's
 * sibling kernel test (`GroupLinksFieldTest`) already uses for its own
 * config-only field (`field_group_links`): kernel tests never auto-install a
 * listed module's `config/install/` directory or invoke `hook_install()` for
 * modules named in `static::$modules` (confirmed by reading
 * `KernelTestBase::bootKernel()` / `DrupalKernel::updateModules()`), so
 * `installConfig(['field'])` alone (redundant with the grandparent
 * `EntityKernelTestBase::setUp()`, which already calls it) can never produce
 * `do_group_extras`'s own field config. The values below are copied verbatim
 * from the shipped YAML so the kernel fixture never drifts from real config:
 * `docs/groups/config/field.storage.group.field_group_about.yml`,
 * `field.field.group.community_group.field_group_about.yml`,
 * `core.entity_view_display.group.community_group.default.yml` (the
 * `field_group_about` component only), and
 * `core.entity_form_display.group.community_group.default.yml` (ditto).
 * These files do not exist yet at RED time — F produces them from this
 * fixture's values as the contract.
 *
 * @group do_group_extras
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class GroupAboutFieldTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * do_group_extras (module under test) + text/field/filter, on top of the
   * group/gnode/node base stack (mirrors GroupLinksFieldTest's pattern).
   * `filter` is required to construct the `basic_html` FilterFormat fixture
   * used by testRendersFormattedBody().
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
    'text',
    'filter',
    'user',
    'do_group_extras',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['field']);

    // Minimal basic_html filter format: only <p> and <strong> need to
    // survive for testRendersFormattedBody()'s assertion. This is NOT the
    // real site's shipped basic_html format — it is a kernel-local stand-in
    // proving the sanitize-then-render pipeline works, per A warn #5.
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML (kernel fixture)',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => [
            'allowed_html' => '<p> <strong>',
          ],
        ],
      ],
    ])->save();

    // Programmatically build field_group_about storage + instance + both
    // displays — the same shape GroupLinksFieldTest uses for
    // field_group_links. Settings mirror the shipped YAML this story's F
    // will produce (see class docblock for the file list) so this fixture
    // never drifts from real config.
    FieldStorageConfig::create([
      'field_name' => 'field_group_about',
      'entity_type' => 'group',
      'type' => 'text_long',
      'cardinality' => 1,
      'translatable' => TRUE,
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_group_about',
      'entity_type' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'label' => 'About',
      'required' => FALSE,
      'translatable' => TRUE,
      'settings' => [
        'allowed_formats' => [],
      ],
    ])->save();

    $view_display = EntityViewDisplay::create([
      'targetEntityType' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $view_display->setComponent('field_group_about', [
      'label' => 'above',
      'type' => 'text_default',
      'weight' => 10,
      'region' => 'content',
      'settings' => [],
      'third_party_settings' => [],
    ]);
    $view_display->save();

    $form_display = EntityFormDisplay::create([
      'targetEntityType' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $form_display->setComponent('field_group_about', [
      'type' => 'text_textarea',
      'weight' => 5,
      'region' => 'content',
      'settings' => [
        'rows' => 5,
        'placeholder' => '',
      ],
      'third_party_settings' => [],
    ]);
    $form_display->save();
  }

  /**
   * `field_group_about` storage exists as a `text_long` field, cardinality 1.
   */
  public function testStorageExists(): void {
    $storage = FieldStorageConfig::loadByName('group', 'field_group_about');

    $this->assertNotNull($storage, 'field_group_about storage config exists on the group entity type.');
    $this->assertSame('text_long', $storage->getType(), 'field_group_about is a core text_long field.');
    $this->assertSame(1, $storage->getCardinality(), 'field_group_about has cardinality 1.');
    $this->assertTrue($storage->isTranslatable(), 'field_group_about storage is translatable.');
  }

  /**
   * `field_group_about` is instanced on `community_group` as a non-required,
   * "About"-labeled, translatable field.
   */
  public function testInstanceExists(): void {
    $instance = FieldConfig::loadByName('group', static::GROUP_TYPE_ID, 'field_group_about');

    $this->assertNotNull($instance, 'field_group_about is instanced on the community_group bundle.');
    $this->assertSame('About', $instance->getLabel(), 'The field label is "About".');
    $this->assertFalse($instance->isRequired(), 'field_group_about is not required.');
    $this->assertTrue($instance->isTranslatable(), 'field_group_about instance is translatable.');
  }

  /**
   * The group Full display exposes field_group_about at weight 10 with
   * label "above" and the text_default formatter.
   */
  public function testFullDisplayShowsField(): void {
    $display = EntityViewDisplay::load('group.' . static::GROUP_TYPE_ID . '.default');

    $this->assertNotNull($display, 'The group community_group "default" (Full) view display exists.');
    $component = $display->getComponent('field_group_about');
    $this->assertNotNull($component, 'field_group_about has a non-hidden component on the Full display.');
    $this->assertSame('text_default', $component['type'] ?? NULL, 'field_group_about uses the text_default formatter.');
    $this->assertSame('above', $component['label'] ?? NULL, 'field_group_about label position is "above".');
    $this->assertSame(10, $component['weight'] ?? NULL, 'field_group_about renders at weight 10.');
  }

  /**
   * The group add/edit form display exposes field_group_about with the
   * text_textarea widget (non-hidden component).
   */
  public function testFormDisplayShowsField(): void {
    $display = EntityFormDisplay::load('group.' . static::GROUP_TYPE_ID . '.default');

    $this->assertNotNull($display, 'The group community_group "default" form display exists.');
    $component = $display->getComponent('field_group_about');
    $this->assertNotNull($component, 'field_group_about has a non-hidden component on the form display.');
    $this->assertSame('text_textarea', $component['type'] ?? NULL, 'field_group_about uses the text_textarea widget.');
  }

  /**
   * A formatted About body renders its sanitized rich HTML in the field
   * wrapper — observable HTML, mechanism-agnostic (A warn #5).
   */
  public function testRendersFormattedBody(): void {
    $group = $this->createGroup([
      'field_group_about' => [
        'value' => '<p><strong>Hello</strong> world.</p>',
        'format' => 'basic_html',
      ],
    ]);

    $html = $this->renderGroupFull($group);

    $this->assertStringContainsString('<strong>Hello</strong>', $html, 'The About body\'s sanitized HTML (<strong>) renders in the field wrapper.');
    $this->assertStringContainsString('world.', $html, 'The About body\'s plain text renders alongside the markup.');
  }

  /**
   * Empty state (a): a group that never had field_group_about set renders NO
   * "About" heading and no bare field wrapper.
   */
  public function testEmptyStateRendersNothingWhenFieldNeverSet(): void {
    // Deliberately does NOT set field_group_about.
    $group = $this->createGroup();

    $html = $this->renderGroupFull($group);

    $this->assertDoesNotMatchRegularExpression('#<h2[^>]*>\s*About\s*#i', $html, 'No <h2> "About" heading renders when the field was never set.');
    $this->assertDoesNotMatchRegularExpression('#<label[^>]*>\s*About\s*#i', $html, 'No <label> "About" renders when the field was never set.');
  }

  /**
   * Empty state (b): a group with field_group_about EXPLICITLY set to an
   * empty value/format tuple renders the same nothing (A warn #4).
   */
  public function testEmptyStateRendersNothingWhenValueExplicitlyEmpty(): void {
    $group = $this->createGroup([
      'field_group_about' => [
        'value' => '',
        'format' => 'basic_html',
      ],
    ]);

    $html = $this->renderGroupFull($group);

    $this->assertDoesNotMatchRegularExpression('#<h2[^>]*>\s*About\s*#i', $html, 'No <h2> "About" heading renders for an explicitly empty value/format tuple.');
    $this->assertDoesNotMatchRegularExpression('#<label[^>]*>\s*About\s*#i', $html, 'No <label> "About" renders for an explicitly empty value/format tuple.');
  }

  /**
   * A group with About prose attaches the do_group_extras/group-about
   * library on the Full display; a group with no About does NOT (A warn #6).
   *
   * Kept alongside (not instead of) the E2E coverage, per the brief's
   * "optional but cheap" guidance — asserting the attach contract here is a
   * few lines given renderGroupFull() already exists.
   */
  public function testLibraryAttachedOnlyWhenAboutNonEmpty(): void {
    $with_about = $this->createGroup([
      'field_group_about' => [
        'value' => '<p>Some About prose.</p>',
        'format' => 'basic_html',
      ],
    ]);
    $view_builder = $this->entityTypeManager->getViewBuilder('group');
    $build_with = $view_builder->view($with_about, 'default');
    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = $this->container->get('renderer');
    $renderer->renderRoot($build_with);
    $libraries_with = $build_with['#attached']['library'] ?? [];
    $this->assertContains('do_group_extras/group-about', $libraries_with, 'The group-about library is attached when About prose is set.');

    $without_about = $this->createGroup();
    $build_without = $view_builder->view($without_about, 'default');
    $renderer->renderRoot($build_without);
    $libraries_without = $build_without['#attached']['library'] ?? [];
    $this->assertNotContains('do_group_extras/group-about', $libraries_without, 'The group-about library is NOT attached when About is empty.');
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
