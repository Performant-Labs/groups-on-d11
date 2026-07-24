<?php

declare(strict_types=1);

namespace Drupal\Tests\do_discovery\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\Core\Config\FileStorage;
use Drupal\do_discovery\Hook\DoDiscoveryHooks;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\RoleInterface;

/**
 * Behavioral test locking the forum-bundle comment field (#182).
 *
 * The `forum` bundle must accept comments so hot-score ordering
 * (do_discovery_hot_score) becomes credible on /trending. Acceptance
 * criteria under test (brief §"Acceptance criteria"):
 *  - AC #2: `entity_field.manager`->getFieldDefinitions('node', 'forum')`
 *    includes a `comment`-type field once the assembled config is installed.
 *  - AC #3 (adapted per A's warn #1/#2 in handoff-A.md): create a forum node,
 *    post a comment on it, run the REAL hot-score recompute path
 *    (`DoDiscoveryHooks::cron()`, the only place the merge into
 *    `do_discovery_hot_score` happens — there is no
 *    `do_discovery_recalculate_hot_scores()` function), and assert the
 *    resulting `do_discovery_hot_score.score` row for that node is > 0.
 *
 * Today (before F attaches `field.field.node.forum.comment.yml`), the forum
 * node type has NO comment field: `$node->hasField('comment')` is FALSE, so
 * `$node->get('comment')` throws, and no comment can be attached at all. This
 * is the intended RED — the test must fail because the field is missing, not
 * because of a bootstrap/setup error.
 *
 * @group do_discovery
 * @group do_tests
 */
class HotScoreForumCommentTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_discovery',
    'comment',
    'field',
    'text',
    'filter',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('comment');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('do_discovery', ['do_discovery_hot_score']);

    // Install the comment field storage + comment type + the article
    // instance's dependencies (comment module config), all of which already
    // ship in config/sync/ today — only the FORUM instance is missing (the
    // gap this issue closes). Installed via the storage APIs (not read from
    // an assumed forum-specific fixture), mirroring StreamsRankingTest's
    // comment schema setup.
    $shipped = new FileStorage($this->shippedConfigDir());
    $entity_type_manager = $this->container->get('entity_type.manager');

    $entity_type_manager->getStorage('comment_type')
      ->create($shipped->read('comment.type.comment'))
      ->save();
    $entity_type_manager->getStorage('field_storage_config')
      ->create($shipped->read('field.storage.node.comment'))
      ->save();

    // Grant the base fixture's non-member current user view access to every
    // group_node:* entity so the forum node (created via addNode()) and its
    // comment are visible/postable in this test, mirroring
    // IcalFeedsTest/StreamsRankingTest's setUp() pattern.
    $permissions = [];
    foreach (static::NODE_BUNDLES as $node_type) {
      $permissions[] = "view group_node:$node_type relationship";
      $permissions[] = "view group_node:$node_type entity";
    }
    $this->createGroupRole([
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);
  }

  /**
   * Resolves the shipped config directory for this test.
   *
   * Walks up from this file, checking both the canonical
   * `docs/groups/config` and the assembled `config/sync` at each level, so
   * it resolves identically whether running from the source tree or an
   * assembled CI layout. Mirrors IcalFeedsTest::shippedConfigDir() (same
   * rationale, same pattern).
   *
   * @return string
   *   Absolute path to the directory holding the shipped config.
   */
  protected function shippedConfigDir(): string {
    $marker = 'field.storage.node.comment.yml';
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
   * AC #2: the forum bundle's field list includes a `comment`-type field.
   *
   * Locks the field-attachment contract directly, independent of the
   * hot-score recompute path exercised by the second test below.
   */
  public function testForumBundleHasCommentField(): void {
    $field_definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('node', 'forum');

    $comment_fields = array_filter(
      $field_definitions,
      static fn ($definition) => $definition->getType() === 'comment',
    );

    $this->assertNotEmpty(
      $comment_fields,
      'The forum bundle has at least one field of type "comment" (field.field.node.forum.comment is attached).'
    );
  }

  /**
   * AC #3: commenting on a forum node makes its hot_score > 0 after cron.
   *
   * Per A's handoff (warn #1/#2): `comment_entity_statistics` is updated by
   * core on comment save automatically, but the `do_discovery_hot_score` row
   * is only written/updated by `DoDiscoveryHooks::cron()` — so this test
   * posts a comment, then invokes the hook class's cron() method directly
   * (the real recompute path, not a stand-in), and asserts the resulting
   * score. Formula: (comment_count × 3) + (view_count × 0.5); one comment,
   * no page views recorded => expected score 3.0.
   */
  public function testForumNodeHotScoreIsPositiveAfterCommentAndCron(): void {
    $group = $this->createGroup();
    $node = $this->addNode($group, 'forum', ['title' => 'Discuss this', 'status' => 1]);

    // The forum bundle must carry a `comment` field before a comment can be
    // posted against it at all — this is where the RED fails today (no such
    // field exists yet), rather than on the score assertion below.
    $this->assertTrue(
      $node->hasField('comment'),
      'The forum node has a "comment" field to post against (field.field.node.forum.comment is attached).'
    );

    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'comment',
      'comment_type' => 'comment',
      'subject' => 'Great thread',
      'comment_body' => ['value' => 'This is a reply.', 'format' => 'plain_text'],
      'uid' => $this->getCurrentUser()->id(),
      'status' => CommentInterface::PUBLISHED,
    ]);
    $comment->save();

    // Invoke the REAL hot-score recompute path. There is no
    // do_discovery_recalculate_hot_scores() function (A's warn #1) — recalc
    // lives only in DoDiscoveryHooks::cron() (#[Hook('cron')]).
    /** @var \Drupal\do_discovery\Hook\DoDiscoveryHooks $hooks */
    $hooks = $this->container->get('class_resolver')->getInstanceFromDefinition(DoDiscoveryHooks::class);
    $hooks->cron();

    $score = $this->container->get('database')->select('do_discovery_hot_score', 'h')
      ->fields('h', ['score'])
      ->condition('h.nid', $node->id())
      ->execute()
      ->fetchField();

    $this->assertNotFalse(
      $score,
      'A do_discovery_hot_score row exists for the commented forum node after cron.'
    );
    $this->assertGreaterThan(
      0,
      (float) $score,
      'The forum node\'s hot_score is > 0 after one comment + a cron recompute (comment_count=1 => score=3.0).'
    );
  }

}
