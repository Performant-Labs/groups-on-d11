<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Functional;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\FileStorage;
use Drupal\do_streams\Hook\DoStreamsHooks;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\RoleInterface;

/**
 * #251 Fix 1 — `/my-feed` must invalidate on group join (PERF-3 audit gap).
 *
 * RED (Phase 4, authored by T before F implements). Regression test for
 * `cache-audit-2026-07-24.md` §2 Scenario 2 / §4 Recommendation 1: today NO
 * hook invalidates `do_streams:user_stream:{uid}` when a
 * `group_relationship_insert` (`group_membership` plugin id) fires, so a
 * user's ALREADY-CACHED `/my-feed` render (keyed on the `user` cache
 * context, per `MyFeedController::buildShell()`) is never proactively
 * busted the moment they join a new group with existing content.
 *
 * Deviation from the brief's literal "join a group -> new post -> appears
 * without cache clear" HTTP-round-trip shape (documented here, not silently
 * substituted): `MyFeedController::buildShell()`'s outer render array
 * declares `#cache[contexts]`/`#cache[tags]` but NO explicit `#cache[keys]`
 * — Drupal's RenderCache only writes/reads a cache entry for an element
 * that carries `keys` (contexts/tags alone bubble metadata to a PARENT
 * cache entry, they do not by themselves create one). Confirmed empirically
 * in a throwaway diagnostic probe (not committed): a real two-request
 * `drupalGet('/my-feed')` round trip through this harness's own served test
 * router shows `X-Drupal-Dynamic-Cache: UNCACHEABLE (poor cacheability)` on
 * every authenticated request regardless of this fix, because the page-level
 * response here never qualifies for Dynamic Page Cache under
 * BrowserTestBase's session-cookie-bearing authenticated requests — so an
 * HTTP-round-trip content-diff assertion would pass (or fail) for reasons
 * UNRELATED to the actual gap (it would never observe a real cache HIT
 * either way, making it a false-negative-proof, not-a-valid-RED test). The
 * REAL, observable mechanism the audit's gap concerns — and the one Fix 1
 * actually touches — is the CACHE TAG ITSELF: whether
 * `Cache::invalidateTags([DoStreamsHooks::userStreamCacheTag($uid)])` fires
 * as a side effect of the real `group_relationship_insert` production
 * trigger (an actual "Join group" form submission through the real route,
 * not a direct `$group->addMember()` API shortcut) — this is what any
 * caller's cache entry (page cache, dynamic page cache, a future
 * render-cache-keyed consumer) actually depends on to invalidate correctly,
 * independent of whether THIS SPECIFIC harness's own response ever reaches
 * cacheable status. Pinned via the cache tag's invalidation CHECKSUM
 * (`cache_tags.invalidator.checksum`), the same mechanism
 * `Drupal\Core\Cache\Cache::invalidateTags()` itself writes to and every
 * cache backend's `getMultiple()` consults before serving a HIT — this is
 * the audit's own suggested acceptable alternative to a full render-diff
 * when the render pipeline makes a direct diff awkward.
 *
 * Layer choice: Functional (BrowserTestBase) — the real "Join group" HTTP
 * form submission (`entity.group.join` route) is the actual production
 * trigger path (mirrors
 * `do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest
 * ::testNonMemberSeesJoinButtonOnOpenGroup()`'s identical
 * `/group/{group}/join` + "Join group" submit pattern), so this is not
 * merely a Kernel-level API-shortcut test of the hook in isolation.
 *
 * @group do_streams
 * @group do_tests
 */
class MyFeedGroupJoinCacheInvalidationTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'views',
    'flag',
    'do_group_pin',
    'do_discovery',
    'do_streams',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The community_group-shaped group type used across every test method.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!\Drupal\node\Entity\NodeType::load('page')) {
      $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);
    }

    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
      'creator_membership' => TRUE,
    ]);

    /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $rt_storage */
    $rt_storage = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
    $rt_storage->save($rt_storage->createFromPlugin(
      $this->groupType,
      'group_node:page',
      ['entity_cardinality' => 0],
    ));

    // Outsider scope role granting BOTH view access on group_node:page
    // content (mirrors MyFeedRouteTest::setUp()) AND 'join group' (mirrors
    // JoinPolicyEnforcementTest::setUp()'s baseline outsider grant) — this
    // suite needs both axes: the joined user must be able to SEE the
    // group's content post-join, and must be able to reach the real
    // entity.group.join route pre-join.
    $permissions = ['view group_node:page relationship', 'view group_node:page entity', 'join group'];
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);

    // views.view.my_feed.yml is a site-level config artifact never
    // module-shipped — install it from the SAME module-local fixture copy
    // MyFeedRouteTest already uses.
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $viewData = $fixtures->read('views.view.my_feed');
    $this->assertNotFalse($viewData, 'The views.view.my_feed fixture exists and is readable.');
    \Drupal::entityTypeManager()->getStorage('view')->create($viewData)->save();
  }

  /**
   * Adds a published node to a group via the group_node relationship.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param string $title
   *   The node title.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved, published node.
   */
  protected function addPublishedNode($group, string $title) {
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'page',
      'title' => $title,
      'status' => 1,
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:page');
    return $node;
  }

  /**
   * Fix 1 (audit §2 Scenario 2 / §4 Rec 1): a real "Join group" form
   * submission (the actual production `group_relationship_insert` trigger,
   * `group_membership` plugin id) must invalidate the joining user's own
   * `do_streams:user_stream:{uid}` cache tag.
   *
   * Pinned via the cache-tag invalidation checksum rather than an HTTP
   * render diff — see this class's own docblock for why a render-diff is
   * not a valid RED in this harness (the outer response never reaches
   * cacheable status regardless of this fix). The checksum
   * (`cache_tags.invalidator.checksum`) is the SAME mechanism every real
   * cache backend (page cache, dynamic page cache, the render cache) reads
   * before serving a HIT — a changed checksum after the join IS the
   * observable proof this fix delivers.
   */
  public function testGroupJoinInvalidatesUserStreamCacheTag(): void {
    $account = $this->drupalCreateUser();

    $group = $this->createGroup(['type' => 'community_group', 'label' => 'Late Join Group']);
    $this->addPublishedNode($group, 'Posted Before Joining');

    $this->drupalLogin($account);

    $tag = DoStreamsHooks::userStreamCacheTag($account->id());
    $checksumBefore = \Drupal::service('cache_tags.invalidator.checksum')->getCurrentChecksum([$tag]);

    // The REAL production trigger: an actual "Join group" form submission
    // through the entity.group.join route, mirroring
    // JoinPolicyEnforcementTest::testNonMemberSeesJoinButtonOnOpenGroup()
    // exactly — NOT a direct $group->addMember() API shortcut, so the real
    // group_relationship_insert hook path fires precisely as production
    // traffic would trigger it.
    $this->drupalGet('/group/' . $group->id() . '/join');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Join group');

    // Confirm the join actually happened (the join itself is not what's
    // under test — the invalidation side effect is), mirroring
    // JoinPolicyEnforcementTest's own membership assertion.
    $active_uids = array_map(
      static fn($m) => $m->getEntity()?->id(),
      $group->getMembers(),
    );
    $this->assertContains($account->id(), $active_uids, 'The join form submission made the user an active member (precondition for this test).');

    $checksumAfter = \Drupal::service('cache_tags.invalidator.checksum')->getCurrentChecksum([$tag]);

    $this->assertNotSame(
      $checksumBefore,
      $checksumAfter,
      sprintf(
        'Fix 1: joining a group via the real "Join group" form must invalidate the joining user\'s own cache tag (%s) — no hook does this today (audit §2 Scenario 2 / §4 Rec 1), so this checksum is unchanged before F implements the fix.',
        $tag,
      ),
    );
  }

  /**
   * Negative control: joining a group must NOT invalidate an unrelated
   * user's stream cache tag — the invalidation must be scoped to the
   * joining member only, never a blanket flush (mirrors
   * `DoStreamsHooks::onFlaggingChange()`'s own scoped-tag discipline this
   * fix is meant to follow).
   */
  public function testGroupJoinDoesNotInvalidateUnrelatedUsersTag(): void {
    $joiningAccount = $this->drupalCreateUser();
    $bystanderAccount = $this->drupalCreateUser();

    $group = $this->createGroup(['type' => 'community_group', 'label' => 'Scoped Invalidation Group']);
    $this->addPublishedNode($group, 'Some Content');

    $bystanderTag = DoStreamsHooks::userStreamCacheTag($bystanderAccount->id());
    $checksumBefore = \Drupal::service('cache_tags.invalidator.checksum')->getCurrentChecksum([$bystanderTag]);

    $this->drupalLogin($joiningAccount);
    $this->drupalGet('/group/' . $group->id() . '/join');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Join group');

    $checksumAfter = \Drupal::service('cache_tags.invalidator.checksum')->getCurrentChecksum([$bystanderTag]);

    $this->assertSame(
      $checksumBefore,
      $checksumAfter,
      'A group join must invalidate ONLY the joining member\'s own user_stream tag, never an unrelated bystander\'s tag (no blanket flush).',
    );
  }

}
