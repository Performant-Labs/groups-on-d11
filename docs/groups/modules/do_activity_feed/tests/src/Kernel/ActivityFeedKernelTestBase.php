<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity_feed\Kernel;

use Drupal\comment\Entity\Comment;
use Drupal\comment\Entity\CommentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\message\Entity\Message;
use Drupal\node\NodeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Shared fixture plumbing for the do_activity_feed kernel test suite (#129).
 *
 * Mirrors do_activity's own `ActivityKernelTestBase`
 * (docs/groups/modules/do_activity/tests/src/Kernel/ActivityKernelTestBase.php)
 * — same module stack, same six-message-template
 * `installConfig(['do_activity'])` step (message templates are optional
 * config, never auto-installed by enableModules()) — plus `do_activity_feed`
 * itself in the enabled-modules list so the new module's services/routes/
 * views-data become available once F implements them.
 *
 * Fixture config for THIS module's own tests lives at
 * tests/fixtures/config/ (module-local), never a source-relative
 * `__DIR__/../../../../config` path, so it resolves identically in the
 * source worktree and in CI's assembled `web/modules/custom/do_activity_feed`
 * layout — see PROJECT_CONTEXT.md's explicit fixture-path gotcha.
 *
 * At RED time `do_activity_feed` does not exist at all (no .info.yml), so
 * `enableModules(['do_activity_feed'])` itself throws — this is the intended
 * RED for every subclass: the failure is "module not found," not a typo or a
 * missing import. Once F ships the module (info.yml, services, routing,
 * views data, the ActivityAggregator/ActivityRowBuilder/
 * ActivityFeedController/ActivityMembershipScope classes), the same setUp()
 * exercises the real thing.
 *
 * @group do_activity_feed
 * @group do_tests
 */
abstract class ActivityFeedKernelTestBase extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'comment',
    'field',
    'text',
    'filter',
    'group',
    'gnode',
    'flag',
    'message',
    'message_notify',
    'views',
    'do_activity',
    'do_streams',
    'do_activity_feed',
  ];

  /**
   * The machine name of the comment field attached to the `post` bundle.
   *
   * Matches do_activity's own ActivityKernelTestBase constant exactly, since
   * ActivityCommentSnippetTest needs a real Comment entity to load a body
   * from.
   */
  protected const COMMENT_FIELD_NAME = 'field_activity_comments';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('flagging');
    $this->installEntitySchema('message');
    $this->installEntitySchema('comment');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'field']);
    // do_activity's six message.template.*.yml + attached field.storage.*/
    // field.field.*.yml live under do_activity's own config/install/ — never
    // auto-installed by enableModules(), same gap ActivityKernelTestBase's
    // own docblock documents. Every one of this suite's fixture Messages
    // needs a real, installed template or Message::create()->save() throws
    // `Drupal\message\MessageException: No valid template found.` before
    // reaching the assertion under test.
    $this->installConfig(['do_activity']);
    // do_activity_feed's own shipped views.view.activity_feed.yml is
    // likewise optional config, never auto-installed by enableModules() —
    // without this, Views::getView('activity_feed') returns NULL inside
    // ActivityFeedController::loadMessagesForMyGroups(), so renderFeed()
    // always returns zero rows regardless of the production code's own
    // correctness (T-green fixture repair; see handoff-F.md's "Tests that
    // look wrong" #1).
    $this->installConfig(['do_activity_feed']);

    CommentType::create([
      'id' => 'comment',
      'label' => 'Default comments',
      'target_entity_type_id' => 'node',
    ])->save();

    FieldStorageConfig::create([
      'entity_type' => 'node',
      'type' => 'comment',
      'field_name' => static::COMMENT_FIELD_NAME,
      'settings' => ['comment_type' => 'comment'],
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'post',
      'field_name' => static::COMMENT_FIELD_NAME,
    ])->save();

    // The comment BUNDLE's own comment_body field instance. Its field
    // STORAGE (field.storage.comment.comment_body.yml) ships under the
    // comment MODULE's own config/install/ — like do_activity's message
    // templates and do_activity_feed's own view above, optional config a
    // module ships is never auto-installed by enableModules(), so it needs
    // an explicit installConfig(['comment']) here (confirmed empirically:
    // omitting it throws "Attempted to create ... an instance of field with
    // name comment_body ... when the field storage does not exist"). Once
    // the storage exists, the per-bundle FieldConfig instance below is still
    // required — without it $comment->hasField('comment_body') is FALSE on
    // every Comment this fixture creates, so ActivityRowBuilder::
    // buildCommentSnippet() cannot read a body at all (T-green fixture
    // repair; see handoff-F.md's "Tests that look wrong" #4). Mirrors core's
    // own CommentManager::addBodyField() and this SAME setUp()'s existing
    // (correct) pattern for the node-side field_activity_comments field
    // above.
    $this->installConfig(['comment']);
    FieldConfig::create([
      'entity_type' => 'comment',
      'bundle' => 'comment',
      'field_name' => 'comment_body',
    ])->save();

    // Grant the base `access content` permission plus group-scoped view
    // permission on the `post` bundle to every authenticated user, mirroring
    // do_streams' own StreamsScopeTest::setUp() (docs/groups/modules/
    // do_streams/tests/src/Kernel/StreamsScopeTest.php). Node access always
    // requires the base `access content` permission at minimum — group
    // membership alone never grants node view access without it — so
    // fixture users created via plain createUser() (zero permissions)
    // otherwise fail $node->access('view') even for a fellow group member's
    // own published content (T-green fixture repair; see handoff-F.md's
    // "Tests that look wrong" #5). `access content` is a PLAIN user
    // permission (checked directly by node_access(), entirely independent of
    // Group's own permission system) — it must be granted on the real
    // `authenticated` user Role CONFIG ENTITY via
    // user_role_grant_permissions(), which requires installConfig(['user'])
    // first (never auto-installed by enableModules(); confirmed empirically:
    // Role::load(RoleInterface::AUTHENTICATED_ID) is NULL, and the grant
    // silently no-ops, without this). The GROUP-scoped
    // `view group_node:post {relationship,entity}` permissions are a
    // SEPARATE, Group-specific permission set granted via createGroupRole()
    // and must not be conflated with `access content` in the same list.
    $this->installConfig(['user']);
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['access content']);

    $postPermissions = [
      'view group_node:post relationship',
      'view group_node:post entity',
    ];
    $this->createGroupRole([
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $postPermissions,
    ]);
    $this->createGroupRole([
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $postPermissions,
    ]);
  }

  /**
   * Creates and saves an `activity_post_created` Message.
   *
   * @param \Drupal\user\UserInterface $actor
   *   The acting user (the post author).
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group context.
   * @param \Drupal\node\NodeInterface $node
   *   The referenced node.
   * @param int $created
   *   The Message's created timestamp.
   *
   * @return \Drupal\message\MessageInterface
   *   The saved Message.
   */
  protected function createPostMessage(UserInterface $actor, GroupInterface $group, NodeInterface $node, int $created) {
    $message = Message::create([
      'template' => 'activity_post_created',
      'uid' => $actor->id(),
      'field_referenced_entity_type' => 'node',
      'field_referenced_entity_id' => $node->id(),
      'field_group_id' => ['target_id' => $group->id()],
    ]);
    $message->setCreatedTime($created);
    $message->save();
    return $message;
  }

  /**
   * Creates and saves an `activity_membership_created` Message.
   *
   * Per the brief's advisory: `field_referenced_entity_type` may be left
   * empty for this template (the user IS the referenced entity) — the row
   * builder is expected to default to 'user' and read the id off
   * `field_referenced_entity_id` (confirmed against #116's own
   * `groupRelationshipInsert()` membership branch, which sets
   * `field_referenced_entity_type => 'user'` explicitly — this helper
   * mirrors that exact shape rather than leaving it empty, since that is
   * what the live hook actually produces).
   *
   * @param \Drupal\user\UserInterface $actor
   *   The new member (the acting user).
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group joined.
   * @param int $created
   *   The Message's created timestamp.
   *
   * @return \Drupal\message\MessageInterface
   *   The saved Message.
   */
  protected function createMembershipMessage(UserInterface $actor, GroupInterface $group, int $created) {
    $message = Message::create([
      'template' => 'activity_membership_created',
      'uid' => $actor->id(),
      'field_referenced_entity_type' => 'user',
      'field_referenced_entity_id' => $actor->id(),
      'field_group_id' => ['target_id' => $group->id()],
    ]);
    $message->setCreatedTime($created);
    $message->save();
    return $message;
  }

  /**
   * Creates a real Comment entity on the given node, plus its activity Message.
   *
   * @param \Drupal\user\UserInterface $actor
   *   The commenter.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group context to stamp on the Message's field_group_id.
   * @param \Drupal\node\NodeInterface $node
   *   The commented node.
   * @param string $body
   *   The raw comment body text (HTML allowed, per AC-5's tag-stripping test).
   * @param int $created
   *   The Message's created timestamp.
   *
   * @return \Drupal\message\MessageInterface
   *   The saved Message referencing the real Comment.
   */
  protected function createCommentMessage(UserInterface $actor, GroupInterface $group, NodeInterface $node, string $body, int $created) {
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => static::COMMENT_FIELD_NAME,
      'uid' => $actor->id(),
      'comment_type' => 'comment',
      'subject' => 'Re: ' . $node->label(),
      'comment_body' => ['value' => $body, 'format' => 'plain_text'],
      'status' => 1,
    ]);
    $comment->save();

    $message = Message::create([
      'template' => 'activity_comment_created',
      'uid' => $actor->id(),
      'field_referenced_entity_type' => 'comment',
      'field_referenced_entity_id' => $comment->id(),
      'field_group_id' => ['target_id' => $group->id()],
    ]);
    $message->setCreatedTime($created);
    $message->save();
    return $message;
  }

}
