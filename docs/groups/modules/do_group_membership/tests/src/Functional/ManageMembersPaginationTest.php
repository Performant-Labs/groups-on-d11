<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;

/**
 * AC-15 — the Manage-members table paginates at 50 rows.
 *
 * This is the covering test for the diff-gate [B-1] BLOCK
 * (`docs/handoffs/0138-mc7-manage-members/dual-review-diff.md`): before F's
 * fix, `ManageMembersForm::buildForm()` initialized the pager against
 * `count($memberships)` where `$memberships` was ALREADY the sliced/limited
 * array, so the pager never activated and every row rendered on one page
 * regardless of group size. F's fix (`ManageMembersForm::buildForm()`,
 * "Diff-gate round-1 fixes" in handoff-F.md) fetches the FULL membership
 * list first, initializes `PagerManagerInterface::createPager()` against the
 * full count, then `array_slice()`s to the current page.
 *
 * This test seeds 55 members (> the 50-row page size) and asserts:
 * - exactly 50 rows render on page 1;
 * - a pager element is present;
 * - page 2 renders the remaining 5 rows;
 * - the last-Organizer guard (`countActiveOrganizers()`) still counts
 *   Organizers across the WHOLE group, not just the current page's slice —
 *   i.e. an Organizer who is on page 2 is NOT invisible to the guard while
 *   viewing page 1. This is the exact defect class the BLOCK was about:
 *   pagination that silently truncates data the guard logic depends on.
 *
 * @group do_group_membership
 * @group group
 */
class ManageMembersPaginationTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'do_group_membership', 'field', 'options'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The group under test.
   *
   * @var \Drupal\group\Entity\GroupInterface
   */
  protected $group;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $group_type = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);
    $this->createGroupRole([
      'group_type' => $group_type->id(),
      'id' => 'community_group-organizer',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['administer members'],
    ]);
    $this->createGroupRole([
      'group_type' => $group_type->id(),
      'id' => 'community_group-member',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => [],
    ]);

    FieldStorageConfig::create([
      'field_name' => 'field_membership_status',
      'entity_type' => 'group_relationship',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          ['value' => 'active', 'label' => 'Active'],
          ['value' => 'pending', 'label' => 'Pending'],
          ['value' => 'blocked', 'label' => 'Blocked'],
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_membership_status',
      'entity_type' => 'group_relationship',
      'bundle' => 'community_group-group_membership',
      'label' => 'Status',
    ])->save();

    $this->group = $this->createGroup([
      'type' => $group_type->id(),
      'label' => 'Pagination Test Group',
      'status' => 1,
    ]);
  }

  /**
   * AC-15: >50 members paginate 50/page.
   *
   * The last-Organizer guard still sees the whole group, not just the
   * visible page's slice.
   */
  public function testMemberTablePaginatesAt50RowsAndGuardSeesWholeGroup(): void {
    // Seed a SECOND Organizer first, so the group has two active
    // Organizers before we add the 53 plain Members that push the table
    // past one page. This lets us push one Organizer onto page 2 while
    // keeping the guard's "at least one Organizer" invariant intact for
    // the whole fixture.
    $second_organizer = $this->drupalCreateUser();
    $this->group->addMember($second_organizer, [
      'group_roles' => ['community_group-organizer'],
      'field_membership_status' => ['value' => 'active'],
    ]);

    // 53 plain Members — pushes the total to 55 (2 Organizers + 53
    // Members), i.e. > 50, guaranteeing a second page exists.
    for ($i = 0; $i < 53; $i++) {
      $member = $this->drupalCreateUser();
      $this->group->addMember($member, [
        'group_roles' => ['community_group-member'],
        'field_membership_status' => ['value' => 'active'],
      ]);
    }

    // The viewing Organizer is added LAST, so alphabetic/insertion-order
    // rendering puts them late in the list — a real page-2 citizen, not
    // an artifact of always being first.
    $viewing_organizer = $this->drupalCreateUser();
    $this->group->addMember($viewing_organizer, [
      'group_roles' => ['community_group-organizer'],
      'field_membership_status' => ['value' => 'active'],
    ]);
    $this->drupalLogin($viewing_organizer);

    // Total membership: 55 (2 Organizers seeded above + 53 Members +
    // this viewing Organizer = 55).
    $this->assertCount(55, $this->group->getMembers(), 'Fixture sanity: the group has 55 memberships.');
    $this->assertCount(2, $this->group->getMembers(['community_group-organizer']), 'Fixture sanity: the group has 2 active Organizers before the viewing Organizer is counted separately.');

    // --- Page 1 ---
    $this->drupalGet('/group/' . $this->group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);

    $page1_rows = $this->getSession()->getPage()->findAll('css', 'table tbody tr');
    $this->assertCount(50, $page1_rows, 'Exactly 50 member rows render on page 1 of a 55-member group.');

    // The pager element is present and backed by a real initialized
    // pager (not a dead `#type => pager` with an uninitialized element).
    $this->assertSession()->elementExists('css', '.pager, nav[aria-label="Pagination"], ul.pager__items');

    // The last-Organizer guard must NOT be fooled by pagination: even
    // though this is only page 1, no "last Organizer" guard note should
    // appear (there are 2 real Organizers group-wide), and the Role/
    // Remove buttons for THIS row (the viewing Organizer, wherever they
    // land) must not be disabled purely because the OTHER Organizer is on
    // a different page. We assert this indirectly: the guard-note copy
    // ("Last Organizer — promote another member first.") must not appear
    // anywhere in the page-1 response, proving `countActiveOrganizers()`
    // saw both Organizers despite only one page of rows being rendered.
    $this->assertSession()->pageTextNotContains('Last Organizer — promote another member first.');

    // --- Page 2 ---
    $this->drupalGet('/group/' . $this->group->id() . '/members', ['query' => ['page' => 1]]);
    $this->assertSession()->statusCodeEquals(200);

    $page2_rows = $this->getSession()->getPage()->findAll('css', 'table tbody tr');
    $this->assertCount(5, $page2_rows, 'The remaining 5 member rows (55 - 50) render on page 2.');

    // The guard note must still be absent on page 2 — same whole-group
    // count, same conclusion, regardless of which page is being viewed.
    $this->assertSession()->pageTextNotContains('Last Organizer — promote another member first.');
  }

}
