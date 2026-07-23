<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\flag\Entity\Flag;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\RoleInterface;
use Drupal\views\Views;

/**
 * Kernel coverage for the `following_feed` view's group-access enforcement.
 *
 * Issue #111 ST-2 / brief acceptance criterion "Group-access negative: a
 * user follows a node in a group they cannot access -> row absent (kernel
 * test preferred; e2e acceptable if fixture-able)." Per survey.md
 * "§Files T (RED) must cover" and handoff-A.md finding §3, this is the
 * honest place to prove it: `do_streams_following_scope`
 * (FollowingScope.php) adds an EXISTS-based WHERE expression to the base
 * `node_field_data` query WITHOUT disabling Views' SQL rewrite, so Drupal's
 * node-access system + the `group` module's grants are relied upon to strip
 * rows from groups the viewer cannot access — the following-scope filter
 * itself does not (and must not need to) special-case group membership.
 * This kernel test asserts that reliance holds for the real `following_feed`
 * view (not just the `do_streams_demo` scaffold used by StreamsScopeTest).
 *
 * The `following_feed` view does not exist yet (brief.md "Files owned":
 * `docs/groups/config/views.view.following_feed.yml` — NEW, F's job). Until
 * F creates it, ::shippedConfigDir() cannot locate the marker file and the
 * test fails its setUp() assertion — this is the intended RED. See
 * handoff-T-red.md for the RED verification output.
 *
 * Config-location resolution mirrors
 * \Drupal\Tests\do_discovery\Kernel\IcalFeedsTest::shippedConfigDir(): walk
 * up from this file's directory checking BOTH `docs/groups/config` (source
 * worktree) and `config/sync` (assembled CI layout, per
 * scripts/ci/assemble-config.sh, which copies docs/groups/config/*.yml into
 * config/sync/). A relative `__DIR__ . '/../../config'`-style path — correct
 * only in the source tree — would silently break in CI's assembled layout,
 * per this project's fixture-path convention (module-local fixtures only;
 * shipped config resolved via directory walk-up, never a source-relative
 * guess).
 *
 * Fixture/setUp mirrors StreamsScopeTest.php's fixture install (module-local
 * flags + field_group_tags field), but its GROUP-ROLE grants deliberately
 * differ, because this test's negative case proves the OPPOSITE thing that
 * StreamsScopeTest's does. StreamsScopeTest grants a non-member BOTH
 * outsider- and insider-scope view access on purpose, so that when its own
 * scope filter (do_streams_membership_scope) excludes a row, the exclusion
 * can only be that filter's doing — Group's access layer would otherwise
 * have let the row through. This test (FollowingFeedTest) instead proves
 * that Drupal's node-access system + Group's OWN grants are what strip a
 * followed-but-inaccessible node — so granting the non-member outsider-scope
 * view access here would defeat the negative case's own premise (the group
 * would no longer be "inaccessible" to that viewer). Only an INSIDER-scope
 * group role is installed (granting members "view group_node:$type
 * relationship"/"entity"), so:
 *  - the negative case's non-member viewer has NO grant on the
 *    "inaccessible" group at all, and
 *  - the sanity companion's viewer, made a member of the "accessible" group,
 *    gets the same insider-scope grant that makes that group's content
 *    visible to them.
 *
 * @group do_streams
 * @group do_tests
 */
class FollowingFeedTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_streams',
    'do_group_pin',
    'do_discovery',
    'flag',
    'views',
    'field',
    'text',
    'filter',
    'datetime',
    'comment',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('flagging');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('flag', ['flag_counts']);
    $this->installSchema('do_discovery', ['do_discovery_hot_score']);

    // Same fixture-install pattern as StreamsScopeTest.php: module-local
    // copies (tests/fixtures/config/, NOT a source-relative ../../../.. path)
    // of the three follow_* flags, stripping the one key with no matching
    // config schema (flagTypeConfig.access_author on follow_content — a
    // pre-existing gap in the shipped config, unrelated to this story).
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager = $this->container->get('entity_type.manager');
    foreach (['flag.flag.follow_content', 'flag.flag.follow_user', 'flag.flag.follow_term'] as $config_name) {
      $values = $fixtures->read($config_name);
      unset($values['flagTypeConfig']['access_author']);
      $entity_type_manager->getStorage('flag')->create($values)->save();
    }

    // taxonomy vocabulary + field_group_tags storage/field-config (the
    // FollowingScope follow_term join target) — not exercised directly by
    // this test's two cases (which use follow_content), but required for
    // the following_feed view's filter config to install without a missing
    // field-storage error, matching StreamsScopeTest's setUp().
    \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->create([
      'vid' => 'group_tags',
      'name' => 'Group Tags',
    ])->save();
    $this->installConfig(['field']);
    $field_storage_config = $fixtures->read('field.storage.node.field_group_tags');
    $entity_type_manager->getStorage('field_storage_config')->create($field_storage_config)->save();
    $field_config = $fixtures->read('field.field.node.page.field_group_tags');
    $entity_type_manager->getStorage('field_config')->create($field_config)->save();

    // Install the SHIPPED following_feed view config so this test proves the
    // real production view, not a scaffold. ::shippedConfigDir() fails the
    // test (via $this->fail()) if views.view.following_feed.yml cannot be
    // found in either the source-tree or assembled-CI config location —
    // that failure IS the intended RED until F creates the file (brief.md
    // "Files owned": docs/groups/config/views.view.following_feed.yml).
    $shipped_config = new FileStorage($this->shippedConfigDir());
    $following_feed_config = $shipped_config->read('views.view.following_feed');
    $this->assertIsArray(
      $following_feed_config,
      'views.view.following_feed.yml exists and parses (F creates this per brief.md "Files owned").'
    );
    $entity_type_manager->getStorage('view')->create($following_feed_config)->save();

    // ONLY an insider-scope group role granting view permission on every
    // group_node:* bundle — deliberately NOT an outsider-scope grant (see
    // class doc comment). Granting outsider-scope view access here would
    // give every authenticated non-member (including this test's negative
    // case's viewer) a blanket view grant on every group of this type,
    // which would make the "inaccessible" group actually accessible and
    // the negative case's own premise vacuous. Only members (via this
    // insider-scope role + an explicit addMember() call) get view access;
    // a non-member has no grant at all, which is exactly what the negative
    // case needs to prove that Drupal's node-access system + Group's own
    // grants (not FollowingScope) are what exclude the row.
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
   * Resolves the directory holding the shipped `following_feed` view config.
   *
   * This test may run with the module in its canonical repo location
   * (`docs/groups/modules/do_streams/`) or from an assembled build where
   * `scripts/ci/assemble-config.sh` has copied `docs/groups/config/*.yml`
   * into `config/sync/`. Walk up from this file until a directory
   * containing the marker YAML is found, checking both the canonical
   * `docs/groups/config` and the assembled `config/sync` at each level —
   * exactly the pattern already proven by
   * \Drupal\Tests\do_discovery\Kernel\IcalFeedsTest::shippedConfigDir().
   *
   * @return string
   *   Absolute path to the directory holding the shipped view config.
   */
  protected function shippedConfigDir(): string {
    $marker = 'views.view.following_feed.yml';
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
   * Executes the following_feed view's page_1 display.
   *
   * @return \Drupal\views\ResultRow[]
   *   The ordered result rows (post-DISTINCT).
   */
  protected function executeFollowingFeed(): array {
    $view = Views::getView('following_feed');
    $this->assertNotNull($view, 'The following_feed view loaded.');
    $view->setDisplay('page_1');
    $view->preExecute();
    $view->execute();
    return $view->result;
  }

  /**
   * @return int[]
   */
  protected function nidsInOrder(array $rows): array {
    return array_map(static fn ($row) => (int) $row->nid, $rows);
  }

  /**
   * A followed node in a group the viewer cannot access must NOT appear.
   *
   * The core group-access negative case (acceptance criterion). The viewer
   * follows the node directly (follow_content) but is NOT a member of the
   * group the node belongs to, and (per setUp()) holds no outsider-scope
   * grant on this group type either — so the viewer genuinely has no view
   * access to this specific group's content. FollowingScope's follow_content
   * EXISTS branch independently matches this node (the flag exists, full
   * stop) — so if the row is absent from the view's result, the exclusion
   * can only be coming from Drupal's node-access system consulting Group's
   * grants for this specific group id (the viewer has no membership row in
   * it and no blanket outsider grant either), NOT from FollowingScope
   * itself. That is exactly the design the brief and handoff-A.md rely on
   * ("Views SQL rewrite ... + group module's grants automatically strip
   * nodes from groups the viewer cannot access"), and this test is the
   * regression net for it.
   */
  public function testFollowedNodeInInaccessibleGroupIsExcluded(): void {
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);

    // A group the viewer is explicitly NOT a member of, and (per setUp()'s
    // insider-only grant) has no view access to at all.
    $inaccessibleGroup = $this->createGroup();
    $followedNode = $this->addNode($inaccessibleGroup, 'page', [
      'title' => 'Followed but in a group I cannot access',
    ]);

    $flagService = $this->container->get('flag');
    $flag = Flag::load('follow_content');
    $this->assertNotNull($flag, 'The follow_content flag is installed.');
    $flagService->flag($flag, $followedNode, $viewer);

    $nids = $this->nidsInOrder($this->executeFollowingFeed());

    $this->assertNotContains(
      (int) $followedNode->id(),
      $nids,
      'A node the viewer follows, but which sits in a group the viewer cannot access, must not appear in the following_feed view — Views SQL rewrite + Group node-access grants strip it even though FollowingScope\'s follow_content branch independently matches.'
    );
  }

  /**
   * Sanity companion: the SAME follow_content match, in an accessible group.
   *
   * Proves the exclusion above is genuinely about group access (not, e.g.,
   * a bug that hides every followed node universally, which would make the
   * negative test above pass VACUOUSLY for the wrong reason). The viewer is
   * made a MEMBER of this second group (picking up setUp()'s insider-scope
   * grant), and the identical follow_content flag is set on a node inside
   * it — that node MUST appear.
   */
  public function testFollowedNodeInAccessibleGroupIsIncluded(): void {
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);

    $accessibleGroup = $this->createGroup();
    $this->addMember($accessibleGroup, $viewer);
    $followedNode = $this->addNode($accessibleGroup, 'page', [
      'title' => 'Followed and in a group I can access',
    ]);

    $flagService = $this->container->get('flag');
    $flag = Flag::load('follow_content');
    $this->assertNotNull($flag, 'The follow_content flag is installed.');
    $flagService->flag($flag, $followedNode, $viewer);

    $nids = $this->nidsInOrder($this->executeFollowingFeed());

    $this->assertContains(
      (int) $followedNode->id(),
      $nids,
      'A node the viewer follows, in a group the viewer CAN access, appears in the following_feed view.'
    );
  }

}
