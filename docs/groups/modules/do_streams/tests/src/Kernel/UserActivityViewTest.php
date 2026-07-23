<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\RoleInterface;
use Drupal\views\Views;

/**
 * Kernel coverage for the `user_activity` view — issue #114 ST-5.
 *
 * Pins the query-result CONTRACT the brief/wireframe mandate for the "Recent
 * posts" block on `/user/{uid}`: a per-AUTHOR (not per-group, not per-viewer
 * membership) stream of the profile owner's PUBLISHED nodes, filtered down to
 * whatever the CURRENT VIEWER's node_access grants allow, newest first, one
 * row per node (no relationship fan-out).
 *
 * Acceptance criteria under test (brief.md checkboxes + decisions.md A-gate
 * hedges):
 *  (a) Published-only — an unpublished node authored by the profile owner
 *      never appears, regardless of viewer.
 *  (b) Author scoping — the view is keyed off the `uid` contextual argument;
 *      another author's node never appears; the profile owner's own node
 *      does appear.
 *  (c) Access-scoping (private group) — a node in a private (non-member-
 *      accessible) group is absent for a viewer who is not a member, and
 *      present for a viewer who is a member. This is the AC this story's O
 *      Phase-1 entry flagged as the key open assumption: `node_access`
 *      relies on gnode's own grants doing the work, no do_streams-specific
 *      access filter is written.
 *  (d) Newest-first ordering (created DESC) — an A-gate hedge, cheap to
 *      assert alongside (b).
 *  (e) distinct — an A-gate hedge: `group_relationship` join fan-out (a node
 *      related to a group via more than one relationship row) must still
 *      surface as exactly one result row.
 *
 * The `user_activity` view does not exist yet (no
 * `docs/groups/config/views.view.user_activity.yml`) — F creates it per
 * brief.md "Files touched". Until then, ::shippedConfigDir() cannot locate
 * the marker file and setUp() fails via $this->fail() — this is the intended
 * RED. Config-location resolution mirrors
 * \Drupal\Tests\do_streams\Kernel\FollowingFeedTest::shippedConfigDir() (walk
 * up from this file checking both `docs/groups/config` [source worktree] and
 * `config/sync` [assembled CI layout]) — the same fixture-path convention
 * this project's CLAUDE.md override calls out (module-local fixtures, never
 * a source-relative `__DIR__/../../../../../config` guess).
 *
 * Layer choice: kernel view-execution, mirroring StreamsScopeTest.php /
 * FollowingFeedTest.php — the author-scoping, published-only, and
 * access-scoping behavior under test is entirely in the compiled SQL a Views
 * contextual argument + filter contribute; executing the real view and
 * reading `$view->result` asserts row-membership and ordering far more
 * precisely than scraping rendered HTML (that DOM-level proof is the
 * Playwright spec's job).
 *
 * @group do_streams
 * @group do_tests
 */
class UserActivityViewTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_streams',
    'views',
    'field',
    'text',
    'filter',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['field']);

    // Install the SHIPPED user_activity view config so this test proves the
    // real production view, not a scaffold. ::shippedConfigDir() fails the
    // test if views.view.user_activity.yml cannot be found in either the
    // source-tree or assembled-CI config location — that failure IS the
    // intended RED until F creates the file (brief.md "Files touched":
    // docs/groups/config/views.view.user_activity.yml).
    $shipped_config = new FileStorage($this->shippedConfigDir());
    $view_config = $shipped_config->read('views.view.user_activity');
    $this->assertIsArray(
      $view_config,
      'views.view.user_activity.yml exists and parses (F creates this per brief.md "Files touched").'
    );
    $this->container->get('entity_type.manager')->getStorage('view')
      ->create($view_config)
      ->save();

    // Outsider-scope group role: granted to every authenticated user who is
    // NOT a member of a given group — mirrors StreamsScopeTest/
    // FollowingFeedTest's own setUp() convention of installing group roles
    // via the storage API, never by reading config/sync YAML. This role is
    // intentionally NOT granted "view" on group_node relationships/entities,
    // so a non-member viewer has no access to a private group's content by
    // default (see testAccessScopingExcludesPrivateGroupNodeForNonMember()).
    //
    // Insider-scope group role: granted to MEMBERS, with view permission on
    // every group_node:* bundle — this is what a member viewer relies on to
    // see a private group's content (see
    // testAccessScopingIncludesPrivateGroupNodeForMember()).
    $permissions = [];
    foreach (static::NODE_BUNDLES as $node_type) {
      $permissions[] = "view group_node:$node_type relationship";
      $permissions[] = "view group_node:$node_type entity";
    }
    $this->createGroupRole([
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);
  }

  /**
   * Resolves the directory holding the shipped `user_activity` view config.
   *
   * Mirrors \Drupal\Tests\do_streams\Kernel\FollowingFeedTest::shippedConfigDir()
   * exactly: walk up from this file until a directory containing the marker
   * YAML is found, checking both the canonical `docs/groups/config` (source
   * worktree) and the assembled `config/sync` (CI layout, populated by
   * scripts/ci/assemble-config.sh) at each level.
   *
   * @return string
   *   Absolute path to the directory holding the shipped view config.
   */
  protected function shippedConfigDir(): string {
    $marker = 'views.view.user_activity.yml';
    $dir = __DIR__;
    while ($dir !== '' && $dir !== DIRECTORY_SEPARATOR) {
      foreach (['docs/groups/config', 'config/sync'] as $candidate) {
        $path = $dir . '/' . $candidate;
        if (is_file($path . '/' . $marker)) {
          return $path;
        }
      }
      $dir = dirname($dir);
    }
    $this->fail("Could not locate shipped $marker in docs/groups/config or config/sync above " . __DIR__);
  }

  /**
   * Executes the `user_activity` view's block display for a given uid arg.
   *
   * @param int $uid
   *   The profile-owner uid to pass as the view's contextual argument.
   *
   * @return \Drupal\views\ResultRow[]
   *   The ordered result rows (post-DISTINCT).
   */
  protected function executeUserActivity(int $uid): array {
    $view = Views::getView('user_activity');
    $this->assertNotNull($view, 'The user_activity view loaded.');
    // The brief mandates a block display (consumed by
    // block.block.do_streams_user_activity.yml); executing it directly with
    // an explicit argument mirrors how the block invokes the view once a URL
    // "user" contextual filter resolves the uid, without requiring a full
    // request/route stack in a Kernel test.
    $view->setDisplay('block_1');
    // ViewExecutable::execute()'s only parameter is a display id, not a
    // contextual-arguments array — the arguments must be supplied via
    // preExecute()/setArguments() instead.
    $view->preExecute([$uid]);
    $view->execute();
    return $view->result;
  }

  /**
   * @param \Drupal\views\ResultRow[] $rows
   *
   * @return int[]
   */
  protected function nidsInOrder(array $rows): array {
    return array_map(static fn ($row) => (int) $row->nid, $rows);
  }

  /**
   * (a) An unpublished node authored by the profile owner never appears.
   *
   * The profile owner authors both a published and an unpublished node in
   * the SAME (accessible) group; the viewer is a group member (so access
   * scoping cannot be the reason the unpublished node is excluded) — the
   * exclusion must come from the view's own `status = 1` filter.
   */
  public function testPublishedOnlyExcludesUnpublishedNode(): void {
    $owner = $this->createUser();
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);

    $group = $this->createGroup();
    $this->addMember($group, $viewer);

    $published = $this->addNode($group, 'page', [
      'title' => 'Published post',
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    $unpublished = $this->addNode($group, 'page', [
      'title' => 'Unpublished draft',
      'uid' => $owner->id(),
      'status' => 0,
    ]);

    $nids = $this->nidsInOrder($this->executeUserActivity((int) $owner->id()));

    $this->assertContains((int) $published->id(), $nids, 'The published node authored by the profile owner appears.');
    $this->assertNotContains((int) $unpublished->id(), $nids, 'The unpublished node authored by the profile owner never appears.');
  }

  /**
   * (b) Author scoping: only the profile-owner uid's nodes appear.
   *
   * Two authors post into the SAME (accessible) group. Executing the view
   * with the profile owner's uid as the contextual argument must include
   * ONLY that owner's node, excluding the other author's node — proving the
   * `uid` contextual argument (not e.g. group membership) is what scopes
   * results.
   */
  public function testAuthorScopingReturnsOnlyProfileOwnersNodes(): void {
    $owner = $this->createUser();
    $otherAuthor = $this->createUser();
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);

    $group = $this->createGroup();
    $this->addMember($group, $viewer);

    $ownerNode = $this->addNode($group, 'page', [
      'title' => "Owner's post",
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    $otherNode = $this->addNode($group, 'page', [
      'title' => "Someone else's post",
      'uid' => $otherAuthor->id(),
      'status' => 1,
    ]);

    $nids = $this->nidsInOrder($this->executeUserActivity((int) $owner->id()));

    $this->assertNotContains((int) $otherNode->id(), $nids, "A different author's node does not appear on the profile owner's activity.");
    $this->assertContains((int) $ownerNode->id(), $nids, "The profile owner's own node appears.");
  }

  /**
   * (c) Access-scoping: a private-group node is absent for a non-member viewer.
   *
   * The profile owner authors a node in a PRIVATE group (a group type with no
   * outsider-scope view grant, per this test's setUp() — only INSIDER_ID is
   * granted). A viewer who is NOT a member of that group must not see the
   * node via the profile activity view, even though the node is published
   * and correctly author-scoped — the exclusion must come from Drupal's
   * node_access system + Group's own grants (the `node_access` filter the
   * view is required to carry per brief.md checkbox list), not from
   * something else.
   */
  public function testAccessScopingExcludesPrivateGroupNodeForNonMember(): void {
    $owner = $this->createUser();
    $outsiderViewer = $this->createUser();

    $privateGroup = $this->createGroup();
    $privateNode = $this->addNode($privateGroup, 'page', [
      'title' => 'Private group post',
      'uid' => $owner->id(),
      'status' => 1,
    ]);

    $this->setCurrentUser($outsiderViewer);
    $nids = $this->nidsInOrder($this->executeUserActivity((int) $owner->id()));

    $this->assertNotContains(
      (int) $privateNode->id(),
      $nids,
      'A node in a private group is excluded from the profile activity view for a viewer who is not a member of that group.'
    );
  }

  /**
   * (c) Sanity companion: the SAME private-group node IS visible to a member.
   *
   * Proves the exclusion above is genuinely about group access (not, e.g., a
   * bug that hides every node universally, which would make the negative
   * test above pass VACUOUSLY for the wrong reason). A second viewer, made a
   * MEMBER of the private group (picking up setUp()'s insider-scope grant),
   * must see the identical node.
   */
  public function testAccessScopingIncludesPrivateGroupNodeForMember(): void {
    $owner = $this->createUser();
    $memberViewer = $this->createUser();

    $privateGroup = $this->createGroup();
    $this->addMember($privateGroup, $memberViewer);
    $privateNode = $this->addNode($privateGroup, 'page', [
      'title' => 'Private group post, visible to members',
      'uid' => $owner->id(),
      'status' => 1,
    ]);

    $this->setCurrentUser($memberViewer);
    $nids = $this->nidsInOrder($this->executeUserActivity((int) $owner->id()));

    $this->assertContains(
      (int) $privateNode->id(),
      $nids,
      'A node in a private group IS visible via the profile activity view to a viewer who IS a member of that group.'
    );
  }

  /**
   * (d) Newest-first ordering: results sort by created DESC.
   *
   * Three nodes authored by the same profile owner, in the same accessible
   * group, created at t1 < t2 < t3. The view's result order must be
   * [t3, t2, t1] — newest first, per brief.md checkbox "created DESC sort".
   */
  public function testResultsOrderNewestFirst(): void {
    $owner = $this->createUser();
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);

    $group = $this->createGroup();
    $this->addMember($group, $viewer);

    $base = 1_700_000_000;
    $oldest = $this->addNode($group, 'page', [
      'title' => 'Oldest post',
      'uid' => $owner->id(),
      'status' => 1,
      'created' => $base,
    ]);
    $middle = $this->addNode($group, 'page', [
      'title' => 'Middle post',
      'uid' => $owner->id(),
      'status' => 1,
      'created' => $base + 100,
    ]);
    $newest = $this->addNode($group, 'page', [
      'title' => 'Newest post',
      'uid' => $owner->id(),
      'status' => 1,
      'created' => $base + 200,
    ]);

    $nids = $this->nidsInOrder($this->executeUserActivity((int) $owner->id()));

    $this->assertSame(
      [(int) $newest->id(), (int) $middle->id(), (int) $oldest->id()],
      array_values(array_intersect($nids, [
        (int) $newest->id(),
        (int) $middle->id(),
        (int) $oldest->id(),
      ])),
      'The three authored nodes appear newest-first (created DESC).'
    );
  }

  /**
   * (e) distinct: a node related to a group via >1 relationship still yields
   * exactly one result row (no fan-out from relationship joins).
   *
   * Mirrors StreamsScopeTest/FollowingFeedTest's fan-out-guard convention
   * (A-gate hedge: "distinct: true ... to prevent row fan-out when
   * node_access joins are present"). The profile owner's node is added to
   * TWO different accessible groups the viewer belongs to — a node_access
   * grant/join keyed by group membership could otherwise multiply the row if
   * `distinct` is not preserved.
   */
  public function testDuplicateGroupRelationshipsYieldOneRowPerNode(): void {
    $owner = $this->createUser();
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);

    $groupOne = $this->createGroup();
    $this->addMember($groupOne, $viewer);
    $groupTwo = $this->createGroup();
    $this->addMember($groupTwo, $viewer);

    $node = $this->addNode($groupOne, 'page', [
      'title' => 'Cross-posted node',
      'uid' => $owner->id(),
      'status' => 1,
    ]);
    // Relate the SAME node to a second group the viewer also belongs to.
    $groupTwo->addRelationship($node, 'group_node:page');

    $nids = $this->nidsInOrder($this->executeUserActivity((int) $owner->id()));

    $this->assertCount(
      1,
      array_filter($nids, static fn ($nid) => $nid === (int) $node->id()),
      'A node related to more than one accessible group appears exactly ONCE, not duplicated by relationship fan-out.'
    );
  }

}
