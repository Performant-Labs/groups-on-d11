<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_membership\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;

/**
 * AC-7, AC-15 (server-renderable parts) — the Manage-members page's rendered
 * markup: a real `<table>` with `<th scope="col">` headers, a status badge
 * carrying BOTH a class-borne color AND a visible text label (never color
 * alone), and the empty-state copy from the wireframe's Screen 2.
 *
 * Client-side keyboard-operability / focus-visible CSS and axe scanning are
 * OUT of scope for this headless PHPUnit suite — those are U's (UI
 * Walkthrough) job. This test pins only what a server-rendered response can
 * prove: real semantic HTML exists, not that a live browser can tab through
 * it.
 *
 * @group do_group_membership
 * @group group
 */
class ManageMembersPageRenderTest extends BrowserTestBase {

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

    // NOTE: FieldStorageConfig::create()'s PHP entity API takes the SIMPLE
    // key=>label 'allowed_values' array (as below). This differs from the
    // structured [{value,label}, ...] array used in the on-disk config YAML
    // (field.storage.*.field_membership_status.yml) - ListItemBase::
    // storageSettingsToConfigData()/FromConfigData() converts between the two
    // shapes at the config-storage boundary. Passing the structured shape
    // directly to create() double-structures it and throws
    // "settings.allowed_values.0.label.0 doesn't exist" - confirmed
    // empirically against unmodified core 11.4.4, not a core/schema bug.
    FieldStorageConfig::create([
      'field_name' => 'field_membership_status',
      'entity_type' => 'group_relationship',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'active' => 'Active',
          'pending' => 'Pending',
          'blocked' => 'Blocked',
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
      'label' => 'Render Test Group',
      'status' => 1,
    ]);
  }

  /**
   * AC-7: the page renders a real `<table>` with `<th scope="col">` headers
   * and a data row per member (not a div-grid).
   */
  public function testMemberListRendersAsRealTableWithScopedHeaders(): void {
    $organizer = $this->drupalCreateUser();
    $this->group->addMember($organizer, [
      'group_roles' => ['community_group-organizer'],
      'field_membership_status' => ['value' => 'active'],
    ]);
    $this->drupalLogin($organizer);

    $this->drupalGet('/group/' . $this->group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'table');
    // At least one <th scope="col"> — real semantic column headers, not a
    // div-grid pretending to be a table (AC-7 / AC-15).
    $this->assertSession()->elementExists('css', 'table th[scope="col"]');
  }

  /**
   * AC-15: the status badge conveys state via BOTH a modifier/data-state
   * class (color) AND a visible text word ("Active"/"Pending"/"Blocked"),
   * never color alone — the wireframe's badge spec.
   */
  public function testStatusBadgeCarriesVisibleTextNotColorAlone(): void {
    $organizer = $this->drupalCreateUser();
    $this->group->addMember($organizer, [
      'group_roles' => ['community_group-organizer'],
      'field_membership_status' => ['value' => 'active'],
    ]);
    $this->drupalLogin($organizer);

    $this->drupalGet('/group/' . $this->group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);
    // A `data-state="active"` (or an --active modifier class) badge element
    // whose own text content includes the human word "Active" — i.e. the
    // meaning is present in the accessible text, not only in a CSS class.
    $badge = $this->assertSession()->elementExists('css', '[data-state="active"]');
    $this->assertStringContainsString('Active', $badge->getText(), 'The status badge carries a visible text label, not color alone.');
  }

  /**
   * AC-7 / Screen 2: the empty-state copy renders when a group has zero
   * relationships (edge case, but the wireframe requires truthful,
   * actionable copy rather than a bare "No members.").
   */
  public function testEmptyGroupShowsGuidingEmptyStateCopy(): void {
    $admin = $this->drupalCreateUser(['administer group']);
    $this->drupalLogin($admin);

    $empty_group = $this->createGroup([
      'type' => 'community_group',
      'label' => 'Empty Group',
      'status' => 1,
    ]);

    $this->drupalGet('/group/' . $empty_group->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('This group has no members yet');
  }

}
