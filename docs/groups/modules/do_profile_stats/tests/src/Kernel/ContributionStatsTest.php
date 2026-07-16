<?php

declare(strict_types=1);

namespace Drupal\Tests\do_profile_stats\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\do_profile_stats\Plugin\Block\ContributionStatsBlock;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Behavioral coverage for the do_profile_stats contribution-stats query.
 *
 * Risk area RA7 / issue #41 (Wave C, C3) of epic #31. The ContributionStats
 * block's {@see ContributionStatsBlock::countGroups()} runs a *raw* SQL select
 * over `group_relationship_field_data` (columns `gid`/`uid`/`type`) with a
 * `gr.type LIKE '%group_membership'` filter and `COUNT(DISTINCT gr.gid)`. That
 * query hard-codes the Group **4.x** data-table + column names and the
 * `group_membership` plugin-id suffix, so static analysis alone cannot confirm
 * it still returns the intended rows against the installed v4 schema — the whole
 * point of RA7. This suite builds a fixture ground truth via the canonical 4.x
 * storage APIs and executes the block's real query against a live DB.
 *
 * DEFECT this suite pins down (RA7 made concrete):
 *   In Group 4.x the membership relationship row's `uid` column is the
 *   *record author* (whoever added the membership — the group owner for
 *   API-added members), while the **member** referenced by the membership is in
 *   the `entity_id` column. `countGroups()` filters on `gr.uid`, so it counts
 *   groups whose membership records a user *authored*, NOT the groups a user is
 *   a *member of*. Concretely, a member added via the API to someone else's
 *   group has their profile report **0** groups
 *   ({@see self::testMemberAddedByAnotherIsMiscountedAsZero()}), while the group
 *   owner is credited with every membership record they created
 *   ({@see self::testCountIsByRecordAuthorNotMember()}). The correct column is
 *   `gr.entity_id` ({@see self::testEntityIdColumnHoldsTheActualMember()}).
 *   LEFT AS-IS (characterization) — surfacing it, with the fix tracked in #41 /
 *   epic #31, mirrors the #38 do_group_pin approach.
 *
 * What IS correct and is asserted as such: the `%group_membership` LIKE excludes
 * `group_node` content relations ({@see self::testLikeExcludesContentRelations()}),
 * `COUNT(DISTINCT gr.gid)` de-dupes ({@see self::testDistinctCollapsesPerGroup()}),
 * the v4 table + `gid`/`uid`/`type` columns exist
 * ({@see self::testV4RelationshipSchemaColumns()}), and the catch → 0 fallback
 * holds for an unknown uid ({@see self::testUnknownUidReturnsZero()}).
 *
 * Layer choice: kernel with live DB + direct query execution — the risk is
 * entirely in the raw SQL against the v4 schema, which executing the query and
 * inspecting the count asserts far more precisely than scraping rendered HTML.
 * No green is faked: the defect tests assert the code's *actual* v4 output and
 * document the intended behavior alongside.
 *
 * @group do_tests
 * @group do_profile_stats
 *
 * @covers \Drupal\do_profile_stats\Plugin\Block\ContributionStatsBlock
 */
final class ContributionStatsTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Adds the module under test, `block` (the plugin manager builds the block),
   * and `comment`: the block's build() also counts comments, so the full
   * build() render path needs a comment schema to query against.
   */
  protected static $modules = [
    'do_profile_stats',
    'block',
    'comment',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Only needed so the full build() render path (countComments()) has a table.
    $this->installEntitySchema('comment');
  }

  /**
   * The v4 membership relationship-type id carries the `%group_membership` suffix.
   *
   * Ground truth the LIKE relies on: the enforced membership relationship type
   * on the community_group type is `community_group-group_membership` (ends in
   * `group_membership`, so `LIKE '%group_membership'` matches) while node
   * relations are `community_group-group_node-*` (so they do not).
   */
  public function testV4MembershipRelationshipTypeId(): void {
    $storage = $this->entityTypeManager->getStorage('group_relationship_type');
    $membership_id = $storage->getRelationshipTypeId(static::GROUP_TYPE_ID, 'group_membership');

    $this->assertSame('community_group-group_membership', $membership_id);
    $this->assertStringEndsWith('group_membership', $membership_id);
    $this->assertStringStartsWith('community_group-group_node', $this->relationshipTypeId('event'),
      'Node relations use the group_node id prefix, which the membership LIKE excludes.');
  }

  /**
   * The raw query targets the v4 schema: table + gid/uid/type columns exist.
   *
   * A wrong table or column name would make countGroups() throw (and its
   * catch → 0 would silently mask the regression). Asserting the schema
   * directly turns that latent failure into an explicit signal and confirms the
   * query's structural assumptions hold against the installed Group 4.x schema.
   */
  public function testV4RelationshipSchemaColumns(): void {
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('group_relationship_field_data'),
      'The v4 relationship data table exists.');
    foreach (['gid', 'uid', 'type', 'entity_id'] as $column) {
      $this->assertTrue(
        $schema->fieldExists('group_relationship_field_data', $column),
        "The v4 relationship data table has the `$column` column."
      );
    }
  }

  /**
   * DEFECT: a member added via the API to another user's group counts as 0.
   *
   * Ground truth: the user IS a member of exactly one group. Intended result of
   * a "groups" contribution stat is therefore 1. But `countGroups()` filters on
   * `gr.uid` — the membership record's *author* (the group owner, who added the
   * member), not the member — so it returns 0. This is the RA7 v4-schema bug:
   * `uid` is the record author, the member is in `entity_id`.
   */
  public function testMemberAddedByAnotherIsMiscountedAsZero(): void {
    // Current user (the group owner/record author) creates the group; a
    // different user is added as a member via the API path.
    $member = $this->createUser();
    $group = $this->createGroup();
    $this->addMember($group, $member);

    // Intended (member-centric) ground truth: the user is in 1 group.
    $intended = $this->distinctGroupsWhereMember((int) $member->id());
    $this->assertSame(1, $intended, 'Fixture ground truth: the user is a member of one group.');

    // Actual v4 behavior of the shipped query: 0 (filters the wrong column).
    $this->assertSame(0, $this->countGroups((int) $member->id()),
      'DEFECT: countGroups() filters gr.uid (record author), not the member, so a member added by someone else counts 0.');
  }

  /**
   * DEFECT: the count is by membership-record author, not by member.
   *
   * The group owner authored the membership records for every member they added,
   * so `countGroups(owner)` counts those groups — even though the number is only
   * coincidentally right for groups the owner also belongs to. Here the owner is
   * a member of the one group they created, and every added member's record is
   * authored by the owner, so `countGroups(owner)` == 1 (one distinct gid),
   * demonstrating the count tracks `gr.uid` = author.
   */
  public function testCountIsByRecordAuthorNotMember(): void {
    // Current user is the group owner / record author.
    $owner = $this->getCurrentUser();
    $group = $this->createGroup();

    // Add three distinct members; the owner authors all three records.
    $members = [$this->createUser(), $this->createUser(), $this->createUser()];
    foreach ($members as $m) {
      $this->addMember($group, $m);
    }

    // All membership rows for this group carry gr.uid = owner, gr.gid = 1.
    // countGroups(owner) collapses them to 1 distinct gid.
    $this->assertSame(1, $this->countGroups((int) $owner->id()),
      'countGroups() credits the record author (owner) with the group, DISTINCT-collapsing the three authored membership rows.');

    // Each actual member, by contrast, counts 0 (same defect as above).
    foreach ($members as $m) {
      $this->assertSame(0, $this->countGroups((int) $m->id()),
        'Each added member counts 0 because the query keys on the record author.');
    }
  }

  /**
   * The `entity_id` column — not `uid` — holds the actual member.
   *
   * Pins the root cause: filtering `gr.entity_id` yields the member-centric
   * ground truth the stat intends, so a corrected query would read `entity_id`.
   * A member in 3 groups counts 3 via entity_id (and 0 via the shipped uid path).
   */
  public function testEntityIdColumnHoldsTheActualMember(): void {
    $member = $this->createUser();
    foreach ([$this->createGroup(), $this->createGroup(), $this->createGroup()] as $group) {
      $this->addMember($group, $member);
    }

    $this->assertSame(3, $this->distinctGroupsWhereMember((int) $member->id()),
      'The member is correctly counted in 3 groups when filtering on entity_id (the real member column).');
    $this->assertSame(0, $this->countGroups((int) $member->id()),
      'The shipped gr.uid path still returns 0 for the same member — the defect, in one place.');
  }

  /**
   * CORRECT: the `%group_membership` LIKE excludes group_node content relations.
   *
   * Adding a node to a group creates a `community_group-group_node-*`
   * relationship row; the membership LIKE must not match it. Asserted from the
   * record-author's perspective (the only perspective the query keys on): the
   * owner authored one membership record and one content record in the same
   * group — the count is 1 (membership only), proving the content row is
   * excluded, not folded in.
   */
  public function testLikeExcludesContentRelations(): void {
    $owner = $this->getCurrentUser();
    $group = $this->createGroup();
    // The owner is credited with one membership record for this group.
    $this->addMember($group, $this->createUser());
    // The owner also authors a group_node content relation in the same group.
    $this->addNode($group, 'event', ['uid' => $owner->id()]);

    // Sanity: the fixture really created a group_node row to be excluded.
    $node_rows = (int) $this->container->get('database')
      ->select('group_relationship_field_data', 'gr')
      ->condition('gr.uid', (int) $owner->id())
      ->condition('gr.type', '%group_node%', 'LIKE')
      ->countQuery()->execute()->fetchField();
    $this->assertGreaterThanOrEqual(1, $node_rows, 'Fixture created a group_node content row authored by the owner.');

    // Count stays 1 (the membership row) — the content row is excluded by LIKE.
    $this->assertSame(1, $this->countGroups((int) $owner->id()),
      'The %group_membership LIKE counts only the membership row and excludes the group_node content relation.');
  }

  /**
   * CORRECT: COUNT(DISTINCT gr.gid) collapses multiple rows per group.
   *
   * The record author holds two membership rows for the SAME group (two members
   * they added), plus a content row. DISTINCT on gid must collapse these to a
   * single group. Asserting 1 proves the DISTINCT is load-bearing.
   */
  public function testDistinctCollapsesPerGroup(): void {
    $owner = $this->getCurrentUser();
    $group = $this->createGroup();
    // Two membership records for the same gid, both authored by the owner.
    $this->addMember($group, $this->createUser());
    $this->addMember($group, $this->createUser());
    // A content row for the same gid too.
    $this->addNode($group, 'event', ['uid' => $owner->id()]);

    $this->assertSame(1, $this->countGroups((int) $owner->id()),
      'COUNT(DISTINCT gr.gid) collapses the two same-group membership rows to a single group.');
  }

  /**
   * CORRECT: an unknown uid yields 0 (empty-count / catch contract).
   *
   * CountGroups() wraps its select in try/catch returning 0 on any \Exception
   * (e.g. a future rename of the table/columns). A uid with no rows returns 0
   * through the normal path; a schema break would route through catch to the
   * same 0 — the fallback degrades to 0 rather than fatalling the profile page.
   */
  public function testUnknownUidReturnsZero(): void {
    $this->assertSame(0, $this->countGroups(999999),
      'An unknown uid yields 0 memberships, matching the empty-count / catch-fallback contract.');
  }

  /**
   * Build() surfaces the (defective) countGroups() number into #groups.
   *
   * Exercises the full public path: getContextUser() (via a route carrying the
   * user parameter) → countGroups() → the themed build array. The record author
   * viewing their own profile sees the count keyed on gr.uid; here the owner
   * authored a membership record in each of two groups (by adding a member to
   * each) and #groups surfaces 2 — confirming build() renders exactly what
   * countGroups() computes, defect included.
   *
   * Note: creating a group via the API (`Group::create()->save()`) does NOT
   * auto-add a creator membership in v4 (CR 2026-04-24, form-only — see #36), so
   * the membership records are established explicitly via addMember().
   */
  public function testBuildRendersComputedGroupCount(): void {
    $owner = $this->getCurrentUser();
    // Owner authors a membership record in each of two groups.
    foreach ([$this->createGroup(), $this->createGroup()] as $group) {
      $this->addMember($group, $this->createUser());
    }

    $this->assertSame(2, $this->countGroups((int) $owner->id()),
      'Precondition: the owner authored a membership record in each of their two groups.');

    // getContextUser() reads the `user` route parameter off the current route
    // match. Build a request matched to a route with a {user} slug and the
    // target user upcast into its attributes, then push it so CurrentRouteMatch
    // resolves getParameter('user') to $owner.
    $route = new Route('/user/{user}', [], ['user' => '\d+']);
    $request = Request::create('/user/' . $owner->id());
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.user.canonical');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('user', $owner);
    $this->container->get('request_stack')->push($request);

    /** @var \Drupal\Core\Block\BlockManagerInterface $block_manager */
    $block_manager = $this->container->get('plugin.manager.block');
    assert($block_manager instanceof BlockManagerInterface);
    $block = $block_manager->createInstance('do_contribution_stats');
    $this->assertInstanceOf(ContributionStatsBlock::class, $block);

    $build = $block->build();
    $this->assertSame('do_contribution_stats', $build['#theme']);
    $this->assertSame(2, $build['#groups'],
      'build() surfaces exactly the number countGroups() computes into the themed #groups.');
  }

  /**
   * Invokes the protected countGroups() with the given uid.
   *
   * A fresh block created off the plugin manager exercises the real create()/DI
   * wiring (the `database` service); reflection then reaches the protected
   * method so each test asserts the raw query in isolation from
   * getContextUser()'s route dependency.
   */
  private function countGroups(int $uid): int {
    $block = $this->container->get('plugin.manager.block')->createInstance('do_contribution_stats');
    $method = new \ReflectionMethod($block, 'countGroups');
    $method->setAccessible(TRUE);
    return (int) $method->invoke($block, $uid);
  }

  /**
   * The member-centric ground truth: distinct groups the uid is a MEMBER of.
   *
   * This is what the contribution stat intends to report. It filters the v4
   * `entity_id` column (the referenced member) rather than `uid` (the record
   * author), so it is the reference the shipped `gr.uid` query is measured
   * against.
   */
  private function distinctGroupsWhereMember(int $uid): int {
    $query = $this->container->get('database')
      ->select('group_relationship_field_data', 'gr')
      ->condition('gr.entity_id', $uid)
      ->condition('gr.type', '%group_membership', 'LIKE');
    $query->addExpression('COUNT(DISTINCT gr.gid)', 'group_count');
    return (int) $query->execute()->fetchField();
  }

}
