<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_extras\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\PermissionScopeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;

/**
 * #143 MC-5 — end-to-end route/access coverage for the Restore action.
 *
 * Route: `do_group_extras.restore` at `/group/{group}/restore` (brief
 * §Design outline). Access: `AccessResult::allowedIf($isArchived &&
 * ($group->hasPermission('edit group', $account) ||
 * $account->hasPermission('administer group')))` — pinned by A r1's
 * perm-string BLOCK fix (Organizer holds 'edit group', not 'administer
 * group'). Non-archived groups AND non-privileged users both get 403
 * (AC-3's single-denial-path convention, matching MMC).
 *
 * Mirrors `ManageMembersRouteAccessTest`'s fixture shape (self-installed
 * BrowserTestBase, `GroupTestTrait` group/role provisioning) plus this
 * story's own `field_group_type` taxonomy-reference fixture (mirrors
 * `GroupExtrasBehaviorTest`'s Kernel-tier fixture).
 *
 * @group do_group_extras
 * @group group
 */
class GroupRestoreAccessTest extends BrowserTestBase {

  use GroupTestTrait;

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
    'do_chrome',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The community_group-shaped group type.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * The "Archive" group_type term.
   */
  protected Term $archiveTerm;

  /**
   * The "Working group" (non-Archive) group_type term.
   */
  protected Term $workingGroupTerm;

  /**
   * An archived test group.
   *
   * @var \Drupal\group\Entity\GroupInterface
   */
  protected $archivedGroup;

  /**
   * A non-archived (Working group-typed) test group, for the AC-3 negative.
   *
   * @var \Drupal\group\Entity\GroupInterface
   */
  protected $activeGroup;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-organizer',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['edit group', 'administer members'],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-member',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => [],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-groups_moderate',
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'admin' => TRUE,
      'global_role' => 'groups_moderate',
    ]);
    $this->createRole([], 'groups_moderate');

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
      'bundle' => 'community_group',
      'label' => 'Group type',
    ])->save();

    $this->archivedGroup = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Legacy Test Group',
      'status' => 1,
      'field_group_type' => $this->archiveTerm->id(),
    ]);
    $this->activeGroup = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Active Test Group',
      'status' => 1,
      'field_group_type' => $this->workingGroupTerm->id(),
    ]);
  }

  /**
   * AC-3: anonymous gets 403 on the restore route of an archived group.
   */
  public function testAnonymousGetsAccessDenied(): void {
    $this->drupalGet('/group/' . $this->archivedGroup->id() . '/restore');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-3: an authenticated non-privileged user gets 403.
   */
  public function testUnprivilegedAuthenticatedUserGetsAccessDenied(): void {
    $outsider = $this->drupalCreateUser();
    $this->drupalLogin($outsider);

    $this->drupalGet('/group/' . $this->archivedGroup->id() . '/restore');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-1: an Organizer of the archived group gets 200 and can submit.
   */
  public function testOrganizerCanRestore(): void {
    $organizer = $this->drupalCreateUser();
    $this->archivedGroup->addMember($organizer, ['group_roles' => ['community_group-organizer']]);
    $this->drupalLogin($organizer);

    $this->drupalGet('/group/' . $this->archivedGroup->id() . '/restore');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([], 'Restore group');

    $this->assertSession()->addressEquals('/group/' . $this->archivedGroup->id());
    $this->assertSession()->pageTextContains('has been restored');

    $storage = \Drupal::entityTypeManager()->getStorage('group');
    $storage->resetCache([$this->archivedGroup->id()]);
    $reloaded = $storage->load($this->archivedGroup->id());
    $this->assertNotSame('Archive', $reloaded->get('field_group_type')->entity?->getName(), 'Group is no longer Archive-typed after restore.');
  }

  /**
   * AC-2: a Groups-Moderate synchronized-role user gets 200.
   */
  public function testGroupsModerateCanAccessRestore(): void {
    $moderator = $this->drupalCreateUser();
    $moderator->addRole('groups_moderate');
    $moderator->save();
    $this->drupalLogin($moderator);

    $this->drupalGet('/group/' . $this->archivedGroup->id() . '/restore');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Site admin (`administer group`) escape hatch gets 200.
   */
  public function testSiteAdminCanAccessRestore(): void {
    $admin = $this->drupalCreateUser(['administer group']);
    $this->drupalLogin($admin);

    $this->drupalGet('/group/' . $this->archivedGroup->id() . '/restore');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * AC-3 amendment: even an Organizer gets 403 on a NON-archived group's
   * restore route — nothing to restore, single denial path (not a 404).
   */
  public function testOrganizerGetsAccessDeniedOnNonArchivedGroup(): void {
    $organizer = $this->drupalCreateUser();
    $this->activeGroup->addMember($organizer, ['group_roles' => ['community_group-organizer']]);
    $this->drupalLogin($organizer);

    $this->drupalGet('/group/' . $this->activeGroup->id() . '/restore');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Site admin also gets 403 on a non-archived group — isArchived gates
   * regardless of privilege level (AC-3).
   */
  public function testSiteAdminGetsAccessDeniedOnNonArchivedGroup(): void {
    $admin = $this->drupalCreateUser(['administer group']);
    $this->drupalLogin($admin);

    $this->drupalGet('/group/' . $this->activeGroup->id() . '/restore');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * AC-4: the confirm control is a real `<button type="submit">`, not a
   * link styled as a button and not an accidental GET-mutation.
   */
  public function testConfirmFormRendersRealSubmitButton(): void {
    $organizer = $this->drupalCreateUser();
    $this->archivedGroup->addMember($organizer, ['group_roles' => ['community_group-organizer']]);
    $this->drupalLogin($organizer);

    $this->drupalGet('/group/' . $this->archivedGroup->id() . '/restore');
    $this->assertSession()->elementExists('css', 'button[type="submit"]');

    // GET alone (page render) must not have mutated the group.
    $storage = \Drupal::entityTypeManager()->getStorage('group');
    $storage->resetCache([$this->archivedGroup->id()]);
    $reloaded = $storage->load($this->archivedGroup->id());
    $this->assertSame('Archive', $reloaded->get('field_group_type')->entity?->getName(), 'Rendering the form via GET does not itself restore the group.');
  }

  /**
   * AC-6: the confirm button's aria-describedby points at an existing id
   * in the rendered DOM (the description paragraph).
   */
  public function testConfirmButtonAriaDescribedbyPointsToExistingId(): void {
    $organizer = $this->drupalCreateUser();
    $this->archivedGroup->addMember($organizer, ['group_roles' => ['community_group-organizer']]);
    $this->drupalLogin($organizer);

    $this->drupalGet('/group/' . $this->archivedGroup->id() . '/restore');
    $button = $this->assertSession()->elementExists('css', 'button[type="submit"]');
    $described_by = $button->getAttribute('aria-describedby');
    $this->assertNotEmpty($described_by, 'Confirm button carries an aria-describedby attribute.');
    $this->assertSession()->elementExists('css', '#' . $described_by);
  }

  /**
   * The Cancel link goes to the group canonical.
   */
  public function testCancelLinkGoesToGroupCanonical(): void {
    $organizer = $this->drupalCreateUser();
    $this->archivedGroup->addMember($organizer, ['group_roles' => ['community_group-organizer']]);
    $this->drupalLogin($organizer);

    $this->drupalGet('/group/' . $this->archivedGroup->id() . '/restore');
    $this->assertSession()->linkByHrefExists('/group/' . $this->archivedGroup->id());
  }

}
