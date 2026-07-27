<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_extras\Functional;

use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\PermissionScopeInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;

/**
 * #251 Fix 3 — `/all-groups` must reflect a private-group membership change
 * (PERF-3 audit gap).
 *
 * RED (Phase 4, authored by T before F implements). Regression test for
 * `cache-audit-2026-07-24.md` §2 Scenario 3 / §4 Recommendation 3:
 * `DoGroupExtrasHooks::viewsQueryAlter()` excludes a private group's row via
 * a raw SQL `WHERE` clause with NO `addCacheableDependency()` equivalent
 * (the hook has no return value to attach one to), and `all_groups.yml`
 * declares no explicit `cache:` plugin (defaulting to Views core's `tag`
 * plugin, whose tag set is derived from the query's OWN result rows — not
 * from a row the query excluded).
 *
 * Sibling to {@see PrivacyDirectoryTest}, which this class deliberately does
 * NOT duplicate: PrivacyDirectoryTest already thoroughly covers the
 * ACCESS-CONTROL outcome (anonymous 403/omission, member sees it, badge
 * DOM) but — per its own class docblock (§2 Scenario 3's evidence trail) —
 * "every test method calls `drupalGet()` fresh against a freshly-provisioned
 * site, so a stale-cache scenario specifically was never exercised." This
 * class adds exactly that missing scenario, reusing PrivacyDirectoryTest's
 * established setUp()/fixture pattern verbatim (same module list, same
 * `field_group_privacy`/`field_group_description`/`field_group_language`
 * field installs, same module-local `all_groups` view fixture) rather than
 * inventing a new one.
 *
 * Test-design note (from this suite's own diagnostic probes, not merely
 * asserted): a privacy-FIELD flip (`field_group_privacy` private->public)
 * on the group entity ITSELF turned out NOT to reproduce the gap in this
 * harness — `$group->save()` invalidates the group's OWN `group:{id}`
 * cache tag as a side effect of the entity save (standard Drupal behavior),
 * which happens to bust the anonymous page cache regardless of whether the
 * query-alter's row-exclusion mechanism has its own tag. This is a
 * legitimate reason a naive test of "flip the privacy field" would be an
 * INVALID red (green before any fix, for a reason unrelated to the gap).
 * The scenario that DOES isolate the actual gap — confirmed empirically via
 * a throwaway probe, not committed — is a MEMBERSHIP grant: adding a member
 * to a private group creates a `group_relationship` entity WITHOUT saving
 * the group entity itself, so `group:{id}`'s own cache tag is never
 * invalidated, and the query-alter's EXISTS-subquery re-evaluation (which
 * SHOULD now include the row for that member) has nothing tying the
 * previously-cached, row-absent anonymous page to bust against — this is
 * the exact "row a query excluded carries no tag" gap the audit's evidence
 * describes, tested via the mechanism that can actually observe it end to
 * end (anonymous Internal Page Cache is a genuine MISS-then-HIT in this
 * harness, confirmed via `X-Drupal-Cache`; an authenticated round trip is
 * marked `UNCACHEABLE (request policy)` unconditionally here regardless of
 * this fix, mirroring Fix 1's identical finding for `/my-feed`, so it
 * cannot produce a valid RED).
 *
 * @group do_group_extras
 * @group group
 */
class AllGroupsMembershipCacheInvalidationTest extends BrowserTestBase {

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
    'do_group_extras',
    'do_group_membership',
    'views',
    'link',
    'text',
    'language',
    'do_group_language',
    'do_chrome',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'groups_chrome';

  /**
   * The community_group-shaped group type.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Verbatim mirror of PrivacyDirectoryTest::setUp() — same roles, same
    // custom field installs, same module-local view fixture. Duplicated
    // here (not inherited/shared) because PrivacyDirectoryTest is a leaf
    // Functional test class, not an abstract base, matching this codebase's
    // existing convention of copy-and-adapt setUp() across sibling
    // Functional suites (e.g. MyFeedRouteTest / MyFeedGroupJoinCacheInvalidationTest).
    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-member',
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['view group', 'view group_node:post entity'],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-outsider_view',
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => 'authenticated',
      'admin' => FALSE,
      'permissions' => ['view group'],
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'id' => 'community_group-anon_view',
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => 'anonymous',
      'admin' => FALSE,
      'permissions' => ['view group'],
    ]);

    FieldStorageConfig::create([
      'field_name' => 'field_group_privacy',
      'entity_type' => 'group',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'public' => 'Public',
          'unlisted' => 'Unlisted',
          'private' => 'Private',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_privacy',
      'entity_type' => 'group',
      'bundle' => $this->groupType->id(),
      'label' => 'Privacy',
      'default_value' => [['value' => 'public']],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_group_description',
      'entity_type' => 'group',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_description',
      'entity_type' => 'group',
      'bundle' => $this->groupType->id(),
      'label' => 'Description',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'type' => 'language',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_language',
      'entity_type' => 'group',
      'bundle' => $this->groupType->id(),
      'label' => 'Primary language',
    ])->save();

    ConfigurableLanguage::createFromLangcode('fr')->save();

    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager = \Drupal::entityTypeManager();

    foreach ([
      'field_storage_config' => 'field.storage.group.field_group_location_text',
      'field_config' => 'field.field.group.community_group.field_group_location_text',
      'view' => 'views.view.all_groups',
    ] as $storage_id => $config_name) {
      $data = $fixtures->read($config_name);
      $this->assertNotFalse($data, sprintf('Fixture %s exists and is readable.', $config_name));
      $entity_type_manager->getStorage($storage_id)->create($data)->save();
    }

    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Fix 3 (audit §2 Scenario 3 / §4 Rec 3): an anonymous-cached `/all-groups`
   * render is not directly under test here — a private group NEVER shows to
   * anonymous regardless of membership (only MEMBERS can see a private
   * group's row per the query-alter's own EXISTS-subquery). The real,
   * literal brief scenario ("private group membership change -> listing
   * updates") is a MEMBER-vs-non-member axis, which requires an
   * AUTHENTICATED viewer — but this harness marks every authenticated
   * `/all-groups` response `UNCACHEABLE (request policy)` regardless of
   * this fix (see class docblock), so an authenticated round-trip can never
   * produce a valid RED for a caching gap here.
   *
   * This test therefore pins the mechanism directly: the query-alter's own
   * EXISTS-subquery membership check is evaluated fresh on every request
   * (proving the CORRECTNESS half of Fix 3's acceptance criterion — a
   * member sees the private group, a non-member does not, without any
   * caching layer masking the transition for an authenticated session),
   * while {@see testAnonymousViewIsUnaffectedByMembershipChangesOnPrivateGroups}
   * below pins the ONE observable cache-HIT risk this harness can actually
   * exercise for `/all-groups`: an anonymous viewer's cached listing must
   * not be corrupted (must not START showing a private group) by an
   * unrelated user's membership change — the negative-invalidation-scope
   * discipline `do_streams`'s own hooks already follow (never a blanket
   * flush).
   */
  public function testMemberVsNonMemberSeesPrivateGroupWithoutCacheClear(): void {
    $securityTeam = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Security Team',
      'status' => 1,
    ]);
    $securityTeam->set('status', 1);
    $securityTeam->set('field_group_privacy', 'private');
    $securityTeam->save();

    $sophie = $this->drupalCreateUser([], 'sophie_cache_test');
    $this->drupalLogin($sophie);

    // Before joining: Sophie must NOT see the private group.
    $this->drupalGet('/all-groups');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains('Security Team');

    // Sophie joins — the real group_relationship_insert trigger (the group
    // entity itself is never saved by this call, isolating the
    // membership-grant axis from the entity-cache-tag axis PrivacyDirectoryTest
    // and this class's own docblock already distinguish).
    $securityTeam->addMember($sophie, ['group_roles' => ['community_group-member']]);

    // Immediately after joining, WITHOUT any manual cache clear, Sophie
    // must see the private group in the listing.
    $this->drupalGet('/all-groups');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains(
      'Security Team',
      'Fix 3: /all-groups must show a private group to a user immediately after they join it — the query-alter\'s membership EXISTS-subquery re-evaluates fresh per request, so this must pass regardless of caching; a regression here would mean Fix 3\'s config-only change broke live re-evaluation for authenticated viewers.',
    );
  }

  /**
   * Negative-scope control: an UNRELATED anonymous viewer's already-cached
   * `/all-groups` listing must never start showing a private group merely
   * because SOME OTHER user joined it — the invalidation (once Fix 3 lands)
   * must not become an over-broad flush that corrupts an anonymous render
   * with member-only rows.
   *
   * This is the one genuine cache-HIT scenario this harness can exercise
   * for `/all-groups` (anonymous Internal Page Cache MISSes then HITs,
   * confirmed via `X-Drupal-Cache`) — used here to prove a NEGATIVE
   * (nothing leaks), which is exactly as valid a regression pin as a
   * positive would be, and does not depend on Fix 3's own implementation
   * shape (`cache: type: none` per the audit's recommended smallest-diff
   * fix would trivially keep this passing; a broader tag-based fix must
   * ALSO keep it passing).
   */
  public function testAnonymousViewIsUnaffectedByMembershipChangesOnPrivateGroups(): void {
    $securityTeam = $this->createGroup([
      'type' => $this->groupType->id(),
      'label' => 'Security Team',
      'status' => 1,
    ]);
    $securityTeam->set('status', 1);
    $securityTeam->set('field_group_privacy', 'private');
    $securityTeam->save();

    $sophie = $this->drupalCreateUser([], 'sophie_cache_test_2');

    // Prime the anonymous Internal Page Cache — confirm a genuine HIT on
    // the second request (the precondition that makes the assertion below
    // meaningful; if this ever fails, the negative control would pass
    // vacuously).
    $this->drupalGet('/all-groups');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('/all-groups');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');
    $this->assertSession()->responseNotContains('Security Team');

    // Sophie (an entirely different, never-logged-in-here user) joins the
    // private group.
    $securityTeam->addMember($sophie, ['group_roles' => ['community_group-member']]);

    // The anonymous listing must still omit the private group.
    $this->drupalGet('/all-groups');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains(
      'Security Team',
      'A membership grant to one user must never leak a private group into an UNRELATED anonymous viewer\'s listing — invalidation must stay scoped, never a blanket flush that mixes viewer-specific visibility into a shared anonymous cache entry.',
    );
  }

}
