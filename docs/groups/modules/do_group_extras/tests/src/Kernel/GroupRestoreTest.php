<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_extras\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\do_group_extras\Form\RestoreGroupForm;
use Drupal\do_group_extras\Hook\DoGroupExtrasHooks;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * #143 MC-5 — Kernel coverage for the Restore action (AC-5, AC-9).
 *
 * Pins the behavior `RestoreGroupForm::submitForm()` must exhibit against a
 * real installed `field_group_type` taxonomy-reference field, mirroring the
 * fixture shape `GroupExtrasBehaviorTest` already established for the archive
 * enforcement hooks (same vocabulary, same Archive/non-Archive term split) so
 * this suite exercises the exact enforcement surface the restore action must
 * flip: `DoGroupExtrasHooks::preprocessGroup()`'s `group--archived` class and
 * `DoGroupExtrasHooks::nodeAccess()`'s create-denial.
 *
 * The form itself is invoked directly (constructed via the container,
 * `buildForm()`/`submitForm()` called programmatically with a rigged
 * `FormState`) rather than through a real HTTP request — the Functional
 * suite (`GroupRestoreAccessTest`) proves the route/access wiring end to end;
 * this Kernel suite proves the field-reassignment + hook-visible-effect
 * behavior in isolation, the cheapest tier that can observe it.
 *
 * @group do_group_extras
 * @group group
 */
#[RunTestsInSeparateProcesses]
class GroupRestoreTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
    'taxonomy',
    'do_group_extras',
  ];

  /**
   * The "Archive" group_type term.
   */
  protected Term $archiveTerm;

  /**
   * The "Working group" (non-Archive) group_type term — the restore target.
   */
  protected Term $workingGroupTerm;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);

    Vocabulary::create(['vid' => 'group_type', 'name' => 'Group type'])->save();
    $this->archiveTerm = Term::create(['vid' => 'group_type', 'name' => 'Archive']);
    $this->archiveTerm->save();
    $this->workingGroupTerm = Term::create(['vid' => 'group_type', 'name' => 'Working group']);
    $this->workingGroupTerm->save();

    FieldStorageConfig::create([
      'field_name' => 'field_group_type',
      'entity_type' => 'group',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_type',
      'entity_type' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'label' => 'Group type',
    ])->save();
  }

  /**
   * Builds a real hook object (route match not consulted by preprocessGroup).
   */
  private function hooks(): DoGroupExtrasHooks {
    $route_match = $this->createMock('Drupal\Core\Routing\RouteMatchInterface');
    return new DoGroupExtrasHooks(
      $this->container->get('current_user'),
      $this->container->get('queue'),
      $route_match,
      $this->container->get('entity_type.manager'),
    );
  }

  /**
   * Renders `preprocess_group` variables for a group, as the theme layer does.
   *
   * @return array
   *   The `$variables` array after the hook runs.
   */
  private function preprocessGroup(\Drupal\group\Entity\GroupInterface $group): array {
    $variables = ['group' => $group, 'attributes' => []];
    $this->hooks()->preprocessGroup($variables);
    return $variables;
  }

  /**
   * Builds a real node (not yet grouped) to probe node_access with.
   */
  private function makeNode(): \Drupal\node\NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => $this->randomMachineName(),
      'uid' => $this->getCurrentUser()->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Constructs the RestoreGroupForm from the real service container.
   *
   * If the class does not exist yet, this itself throws — the expected RED
   * signal (class not found) before F implements the form.
   */
  private function restoreForm(): RestoreGroupForm {
    return RestoreGroupForm::create($this->container);
  }

  /**
   * AC-9 (before-state): a group tagged Archive reads Archive, carries the
   * `group--archived` class, and denies node `create`.
   */
  public function testArchivedGroupPreconditions(): void {
    $group = $this->createGroup(['field_group_type' => $this->archiveTerm->id()]);

    $this->assertSame('Archive', $group->get('field_group_type')->entity->getName());

    $variables = $this->preprocessGroup($group);
    $this->assertContains('group--archived', $variables['attributes']['class'] ?? [], 'Archived group carries the group--archived class before restore.');

    $node = $this->makeNode();
    $hooks = $this->hooks();
    // nodeAccess reads the routed group via the route match; construct a
    // route-aware hook instance for this one assertion.
    $route_match = $this->createMock('Drupal\Core\Routing\RouteMatchInterface');
    $route_match->method('getParameter')->with('group')->willReturn($group);
    $routedHooks = new DoGroupExtrasHooks(
      $this->container->get('current_user'),
      $this->container->get('queue'),
      $route_match,
      $this->container->get('entity_type.manager'),
    );
    $result = $routedHooks->nodeAccess($node, 'create', $this->getCurrentUser());
    $this->assertTrue($result->isForbidden(), 'node create is forbidden in the archived group before restore.');
  }

  /**
   * AC-5, AC-9: submitting the restore form reassigns field_group_type off
   * Archive, clears the group--archived class, and neutralizes node create.
   */
  public function testSubmitRestoresArchivedGroup(): void {
    $group = $this->createGroup(['field_group_type' => $this->archiveTerm->id()]);

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('group_type', $this->workingGroupTerm->id());

    $built = $this->restoreForm()->buildForm($form, $form_state, $group);
    $this->restoreForm()->submitForm($built, $form_state);

    $group = $this->reloadGroup($group);
    $this->assertNotSame('Archive', $group->get('field_group_type')->entity?->getName(), 'field_group_type is reassigned off Archive after restore.');
    $this->assertSame('Working group', $group->get('field_group_type')->entity?->getName());

    $variables = $this->preprocessGroup($group);
    $this->assertNotContains('group--archived', $variables['attributes']['class'] ?? [], 'group--archived class disappears after restore.');

    $node = $this->makeNode();
    $route_match = $this->createMock('Drupal\Core\Routing\RouteMatchInterface');
    $route_match->method('getParameter')->with('group')->willReturn($group);
    $routedHooks = new DoGroupExtrasHooks(
      $this->container->get('current_user'),
      $this->container->get('queue'),
      $route_match,
      $this->container->get('entity_type.manager'),
    );
    $result = $routedHooks->nodeAccess($node, 'create', $this->getCurrentUser());
    $this->assertFalse($result->isForbidden(), 'node create is no longer forbidden after restore.');
    $this->assertInstanceOf(\Drupal\Core\Access\AccessResultNeutral::class, $result, 'node create returns NEUTRAL (not forbidden), matching the non-Archive branch.');
  }

  /**
   * Race guard (wireframe Surface 2 / A r1 WARN-3): if the group is no
   * longer Archive-typed by the time submitForm runs, no reassignment
   * happens and a warning is added — no silent overwrite of a type another
   * actor already changed.
   */
  public function testSubmitIsNoOpWhenGroupNoLongerArchived(): void {
    $group = $this->createGroup(['field_group_type' => $this->workingGroupTerm->id()]);
    $original_tid = $group->get('field_group_type')->target_id;

    $form = [];
    $form_state = new FormState();
    $form_state->setValue('group_type', $this->workingGroupTerm->id());

    $built = $this->restoreForm()->buildForm($form, $form_state, $group);
    $this->restoreForm()->submitForm($built, $form_state);

    $group = $this->reloadGroup($group);
    $this->assertSame($original_tid, $group->get('field_group_type')->target_id, 'No field change when the group was already non-Archive at submit time.');

    $messages = \Drupal::messenger()->all();
    $this->assertNotEmpty($messages[\Drupal\Core\Messenger\MessengerInterface::TYPE_WARNING] ?? [], 'A warning is added when the race guard trips.');
  }

  /**
   * Empty-vocabulary guard (wireframe Surface 3): if the only group_type
   * term is "Archive" itself, buildForm() must refuse to render the normal
   * select/submit controls and instead return a #markup block.
   */
  public function testBuildFormRefusesWhenNoNonArchiveTermExists(): void {
    // Delete the non-Archive term so the vocabulary contains only Archive.
    $this->workingGroupTerm->delete();

    $group = $this->createGroup(['field_group_type' => $this->archiveTerm->id()]);

    $form = [];
    $form_state = new FormState();
    $built = $this->restoreForm()->buildForm($form, $form_state, $group);

    $this->assertArrayNotHasKey('group_type', $built, 'No select renders when there is no non-Archive term to restore to.');
    $this->assertArrayHasKey('#markup', $built, 'An explanatory #markup block is returned instead.');
  }

  /**
   * Reloads a group entity to observe post-save state (bypassing any static
   * cache the entity storage may hold from the in-request save).
   */
  private function reloadGroup(\Drupal\group\Entity\GroupInterface $group): \Drupal\group\Entity\GroupInterface {
    $storage = $this->entityTypeManager->getStorage('group');
    $storage->resetCache([$group->id()]);
    return $storage->load($group->id());
  }

}
