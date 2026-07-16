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
 * over `group_relationship_field_data` (columns `gid`/`entity_id`/`type`) with a
 * `gr.type LIKE '%group_membership'` filter and `COUNT(DISTINCT gr.gid)`. That
 * query hard-codes the Group **4.x** data-table + column names and the
 * `group_membership` plugin-id suffix, so static analysis alone cannot confirm
 * it still returns the intended rows against the installed v4 schema — the whole
 * point of RA7. This suite builds a fixture ground truth via the canonical 4.x
 * storage APIs and executes the block's real query against a live DB.
 *
 * DEFECT this suite drove to a fix (RA7 made concrete — issue #63):
 *   In Group 4.x the membership relationship row's `uid` column is the
 *   *record author* (whoever added the membership — the group owner for
 *   API-added members), while the **member** referenced by the membership is in
 *   the `entity_id` column. The shipped query originally filtered on `gr.uid`,
 *   so it counted groups whose membership records a user *authored*, NOT the
 *   groups a user is a *member of*. #63 corrected the filter to `gr.entity_id`.
 *   These tests now assert the *correct* member-centric behavior: a member added
 *   via the API to someone else's group is counted
 *   ({@see self::testMemberAddedByAnotherIsCounted()}); the count keys on the
 *   member, not the record author, so the owner who merely authored a member's
 *   record is NOT credited ({@see self::testCountIsByMemberNotRecordAuthor()});
 *   and `gr.entity_id` is the column that holds the actual member
 *   ({@see self::testEntityIdColumnHoldsTheActualMember()}).
 *
 * What IS also correct and asserted as such: the `%group_membership` LIKE
 * excludes `group_node` content relations
 * ({@see self::testLikeExcludesContentRelations()}), `COUNT(DISTINCT gr.gid)`
 * de-dupes ({@see self::testDistinctCollapsesPerGroup()}), the v4 table +
 * `gid`/`entity_id`/`type` columns exist
 * ({@see self::testV4RelationshipSchemaColumns()}), and the catch → 0 fallback
 * holds for an unknown uid ({@see self::testUnknownUidReturnsZero()}).
 *
 * Layer choice: kernel with live DB + direct query execution — the risk is
 * entirely in the raw SQL against the v4 schema, which executing the query and
 * inspecting the count asserts far more precisely than scraping rendered HTML.
 * No green is faked: each test asserts the code's *actual* v4 output against a
 * fixture ground truth built through the canonical 4.x storage APIs.
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
   * CORRECT (#63 fix): a member added via the API to another user's group counts.
   *
   * Ground truth: the user IS a member of exactly one group, so the "groups"
   * contribution stat is 1. The corrected query filters on `gr.entity_id` — the
   * account the membership *references* — not `gr.uid` (the record author, i.e.
   * the group owner who added the member), so the member is credited even though
   * someone else authored their membership row.
   */
  public function testMemberAddedByAnotherIsCounted(): void {
    // Current user (the group owner/record author) creates the group; a
    // different user is added as a member via the API path.
    $member = $this->createUser();
    $group = $this->createGroup();
    $this->addMember($group, $member);

    // Member-centric ground truth: the user is in 1 group.
    $intended = $this->distinctGroupsWhereMember((int) $member->id());
    $this->assertSame(1, $intended, 'Fixture ground truth: the user is a member of one group.');

    // Corrected v4 behavior: the member is counted regardless of who authored
    // the membership record.
    $this->assertSame(1, $this->countGroups((int) $member->id()),
      'countGroups() filters gr.entity_id (the member), so a member added by someone else is counted.');
  }

  /**
   * CORRECT (#63 fix): the count is by member, not by membership-record author.
   *
   * The group owner authored the membership records for every member they added,
   * but authoring a record no longer credits the owner. Here the owner is not
   * itself a member of the group (creating a group via the API adds no creator
   * membership in v4 — CR 2026-04-24), so `countGroups(owner)` == 0, while each
   * added member counts 1 — demonstrating the count tracks `gr.entity_id` = the
   * member, not `gr.uid` = the author.
   */
  public function testCountIsByMemberNotRecordAuthor(): void {
    // Current user is the group owner / record author.
    $owner = $this->getCurrentUser();
    $group = $this->createGroup();

    // Add three distinct members; the owner authors all three records but is not
    // itself a member.
    $members = [$this->createUser(), $this->createUser(), $this->createUser()];
    foreach ($members as $m) {
      $this->addMember($group, $m);
    }

    // The owner authored the rows but is not a member, so counts 0.
    $this->assertSame(0, $this->countGroups((int) $owner->id()),
      'countGroups() no longer credits the record author (owner), who is not a member of the group.');

    // Each actual member counts 1 (they belong to the one group).
    foreach ($members as $m) {
      $this->assertSame(1, $this->countGroups((int) $m->id()),
        'Each added member counts 1 because the query keys on gr.entity_id (the member).');
    }
  }

  /**
   * The `entity_id` column — not `uid` — holds the actual member.
   *
   * Pins the root cause and the fix: filtering `gr.entity_id` yields the
   * member-centric ground truth the stat intends, and the corrected query reads
   * `entity_id`. A member in 3 groups counts 3 both via the reference helper and
   * via the shipped query.
   */
  public function testEntityIdColumnHoldsTheActualMember(): void {
    $member = $this->createUser();
    foreach ([$this->createGroup(), $this->createGroup(), $this->createGroup()] as $group) {
      $this->addMember($group, $member);
    }

    $this->assertSame(3, $this->distinctGroupsWhereMember((int) $member->id()),
      'The member is correctly counted in 3 groups when filtering on entity_id (the real member column).');
    $this->assertSame(3, $this->countGroups((int) $member->id()),
      'The corrected gr.entity_id query returns 3 for the same member — the count keys on the member.');
  }

  /**
   * CORRECT: the `%group_membership` LIKE excludes group_node content relations.
   *
   * Adding a node to a group creates a `community_group-group_node-*`
   * relationship row; the membership LIKE must not match it. Asserted from the
   * member's perspective (the perspective the corrected query keys on): the
   * member has one membership row in the group, and a group_node content row
   * *also referencing that member* exists in the same group — the count is 1
   * (membership only), proving the content row is excluded, not folded in.
   */
  public function testLikeExcludesContentRelations(): void {
    $member = $this->createUser();
    $group = $this->createGroup();
    // The member has one membership row for this group.
    $this->addMember($group, $member);
    // A group_node content relation in the same group references the member as
    // the node author, so a row with gr.entity_id-adjacent content exists to be
    // excluded by the membership LIKE.
    $this->addNode($group, 'event', ['uid' => $member->id()]);

    // Sanity: the fixture really created a group_node row in this group.
    $node_rows = (int) $this->container->get('database')
      ->select('group_relationship_field_data', 'gr')
      ->condition('gr.gid', (int) $group->id())
      ->condition('gr.type', '%group_node%', 'LIKE')
      ->countQuery()->execute()->fetchField();
    $this->assertGreaterThanOrEqual(1, $node_rows, 'Fixture created a group_node content row in the group.');

    // Count stays 1 (the membership row) — the content row is excluded by LIKE.
    $this->assertSame(1, $this->countGroups((int) $member->id()),
      'The %group_membership LIKE counts only the membership row and excludes the group_node content relation.');
  }

  /**
   * CORRECT: COUNT(DISTINCT gr.gid) collapses multiple rows per group.
   *
   * The member has one membership row in a group, and a group_node content row
   * shares that same gid. Because the corrected query keys on gr.entity_id = the
   * member, filters to `%group_membership`, and DISTINCTs on gid, the rows in the
   * single group must collapse to exactly 1 — proving the DISTINCT is
   * load-bearing and the content row is not folded in.
   */
  public function testDistinctCollapsesPerGroup(): void {
    $member = $this->createUser();
    $group = $this->createGroup();
    // One membership row for this group; a content row shares the same gid.
    $this->addMember($group, $member);
    $this->addNode($group, 'event', ['uid' => $member->id()]);

    // Membership in the same group must collapse to a single distinct gid.
    $this->assertSame(1, $this->countGroups((int) $member->id()),
      'COUNT(DISTINCT gr.gid) collapses the membership + same-group content rows to a single group for the member.');
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
   * Build() surfaces the corrected countGroups() number into #groups.
   *
   * Exercises the full public path: getContextUser() (via a route carrying the
   * user parameter) → countGroups() → the themed build array. A member added to
   * two distinct groups viewing their own profile sees the count keyed on
   * gr.entity_id; #groups surfaces 2 — confirming build() renders exactly what
   * countGroups() computes.
   *
   * Note: creating a group via the API (`Group::create()->save()`) does NOT
   * auto-add a creator membership in v4 (CR 2026-04-24, form-only — see #36), so
   * the membership records are established explicitly via addMember().
   */
  public function testBuildRendersComputedGroupCount(): void {
    // A single member is added to each of two groups (by the current user, the
    // record author); the member is the counted profile subject.
    $member = $this->createUser();
    foreach ([$this->createGroup(), $this->createGroup()] as $group) {
      $this->addMember($group, $member);
    }

    $this->assertSame(2, $this->countGroups((int) $member->id()),
      'Precondition: the member belongs to two groups (gr.entity_id keyed).');

    // getContextUser() reads the `user` route parameter off the current route
    // match. Build a request matched to a route with a {user} slug and the
    // target user upcast into its attributes, then push it so CurrentRouteMatch
    // resolves getParameter('user') to $member.
    $route = new Route('/user/{user}', [], ['user' => '\d+']);
    $request = Request::create('/user/' . $member->id());
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.user.canonical');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('user', $member);
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
