<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Functional;

use Drupal\group\Entity\GroupMembership;
use Drupal\Tests\group\Functional\GroupBrowserTestBase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * RA2 (#36 / B2) — creator auto-membership is form-only: the FORM half.
 *
 * Group 4.x moved creator auto-membership into the create *form* (CR
 * 2026-04-24). This functional test exercises the real request stack: it logs
 * in a user with permission to create a `community_group`, submits the actual
 * add form at `/group/add/community_group`, and asserts the creator IS a member
 * of the resulting group.
 *
 * This is the behavior the programmatic entity-API path lacks — see the kernel
 * CreatorMembershipApiTest, which proves `Group::create()->save()` yields a
 * memberless group. Together they pin the full contrast: form ⇒ creator is a
 * member; API ⇒ creator is not, until `addMember()` is called.
 *
 * The `community_group` group type is reconstructed here via the Group test
 * trait (`createGroupType(['creator_membership' => TRUE])`) rather than imported
 * from `config/sync`, mirroring how the kernel base assembles it — the assertion
 * is about the form's runtime behavior, not config packaging.
 *
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class CreatorMembershipFormTest extends GroupBrowserTestBase {

  /**
   * The group type id under test (mirrors the assembled community_group).
   */
  protected const GROUP_TYPE_ID = 'community_group';

  /**
   * The group type label (drives the "Create <label>" submit button text).
   */
  protected const GROUP_TYPE_LABEL = 'Community Group';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Grants the global permissions the group creator needs.
   *
   * @return string[]
   *   The site-level permissions.
   */
  protected function getGlobalPermissions() {
    // 'administer group' lets the account reach the group UI; the per-type
    // 'create <type> group' permission is granted in setUp() once the type
    // exists.
    return ['administer group'] + parent::getGlobalPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Reconstruct the community_group type with form-only creator membership on.
    $this->createGroupType([
      'id' => self::GROUP_TYPE_ID,
      'label' => self::GROUP_TYPE_LABEL,
      'creator_membership' => TRUE,
    ]);

    // Log in a user and grant its role(s) the per-type create permission.
    $this->setUpAccount();
    foreach ($this->groupCreator->getRoles(TRUE) as $role_id) {
      $role = $this->entityTypeManager()->getStorage('user_role')->load($role_id);
      $this->assertInstanceOf(RoleInterface::class, $role);
      $role->grantPermission('create ' . self::GROUP_TYPE_ID . ' group')->save();
    }
  }

  /**
   * Submitting the real add form makes the creator a member (form-only RA2).
   *
   * With `creator_membership: TRUE`, the create form's submit button reads
   * "Create <label> and become a member"; submitting it must yield a group whose
   * owner (the logged-in creator) is a member — the behavior the API path lacks.
   */
  public function testFormCreateAddsCreatorAsMember(): void {
    $this->drupalGet('group/add/' . self::GROUP_TYPE_ID);
    $this->assertSession()->statusCodeEquals(200);

    // creator_membership: TRUE appends " and become a member" to the button.
    $submit_button = 'Create ' . self::GROUP_TYPE_LABEL . ' and become a member';
    $this->assertSession()->buttonExists($submit_button);

    $this->submitForm(['label[0][value]' => 'Form-created group'], $submit_button);

    // The created group is owned by the logged-in creator.
    $group = $this->entityTypeManager()->getStorage('group')->load(1);
    $this->assertNotNull($group, 'The add form created a group.');
    $this->assertEquals(
      $this->groupCreator->id(),
      $group->getOwnerId(),
      'The logged-in user owns the form-created group.',
    );

    // THE form-path assertion: the creator IS a member of the resulting group.
    $member = $group->getMember($this->groupCreator);
    $this->assertInstanceOf(
      GroupMembership::class,
      $member,
      'Creating a community_group through the add form adds the creator as a member (form-only in 4.x).',
    );
    $this->assertNotEmpty(
      $group->getMembers(),
      'The form-created group has at least the creator membership.',
    );
  }

}
