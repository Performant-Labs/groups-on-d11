<?php

declare(strict_types=1);

namespace Drupal\do_activity_feed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\do_activity_feed\Service\ActivityAggregator;
use Drupal\do_activity_feed\Service\ActivityRowBuilder;
use Drupal\group\Entity\GroupInterface;
use Drupal\message\MessageInterface;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the do_activity log as an interleaved feed (#129 ST-7).
 *
 * Extends {@see ControllerBase} (rather than being a plain autowired class)
 * because {@see \Drupal\Core\DependencyInjection\ClassResolver::
 * getInstanceFromDefinition()} — the exact resolution path
 * ActivityFeedRenderTest/ActivityAggregationTest use via
 * `\Drupal::classResolver(ActivityFeedController::class)` — only passes the
 * container through for a class implementing
 * `ContainerInjectionInterface` (which ControllerBase does); any other class
 * name is `new`'d with ZERO constructor arguments, which would break this
 * controller's DI entirely.
 *
 * Two entry points share ONE render array builder,
 * {@see self::renderFeed()} — the exact contract survey.md's "Forward-
 * compat check" commits to for ST-8 (#130) reuse:
 *   - {@see self::myGroups()} — `/activity`, the current viewer's
 *     `field_group_id` membership scope, driven by the `activity_feed`
 *     view's `default` display (which carries the
 *     `do_activity_feed_membership_scope` filter, A-advisory #1/#3).
 *   - {@see self::groupScope()} — `/activity/group/{group}`, a single fixed
 *     group. A fixed single-group condition needs no Views contextual-
 *     argument machinery, so this path queries `Message` entities directly
 *     via an EntityQuery scoped to `field_group_id = $group->id()` — no
 *     Views executable involved for this path (see
 *     config/install/views.view.activity_feed.yml's own docblock).
 *
 * ROW-BUILDING PIPELINE (both entry points funnel through this):
 *   1. Load messages (Views executable, or EntityQuery for group scope),
 *      restricted to the three templates that carry `field_group_id` (per
 *      brief §"Which templates surface on /activity" — the OTHER three
 *      templates are excluded at the QUERY level, not filtered out here).
 *   2. Split by template. `activity_post_created` and
 *      `activity_comment_created` are AGGREGABLE (survey.md §"Aggregation
 *      rule"); `activity_membership_created` is not — every one of its
 *      Messages becomes its own `social_join` row directly.
 *   3. For each aggregable template's Message list (already `created` DESC
 *      from the query), run {@see ActivityAggregator::aggregate()}. A
 *      bucket with `count >= 2` becomes ONE `aggregated` row; a
 *      `count === 1` bucket becomes its own underlying row type
 *      (`content_card` for `activity_post_created`, `social_comment` for
 *      `activity_comment_created`) — survey.md §"Aggregation rule" step 3.
 *   4. {@see ActivityRowBuilder::buildRow()} shapes each non-aggregated
 *      Message into its row array; a `content_card` row builder call MAY
 *      return NULL (the referenced node is not viewable — brief §Access),
 *      in which case the row (and, for an aggregate bucket, the WHOLE
 *      bucket if every member is unviewable) is dropped.
 *   5. Every produced row (single + aggregated) is re-merged and sorted by
 *      `created` DESC for final feed order — the per-template split in
 *      step 2 does not itself preserve cross-template chronological order.
 */
class ActivityFeedController extends ControllerBase {

  /**
   * Message templates that carry `field_group_id` and so ever surface here.
   *
   * Per brief §"Which templates surface on /activity": the other three
   * templates (`activity_flagging_created`, `activity_group_created`,
   * `activity_pin_toggled`) carry no `field_group_id` at all and are
   * deliberately out of scope for this POC story.
   */
  private const SURFACING_TEMPLATES = [
    'activity_post_created',
    'activity_comment_created',
    'activity_membership_created',
  ];

  /**
   * Message templates ActivityAggregator collapses runs of.
   *
   * `activity_membership_created` is NOT aggregable per survey.md's
   * "Aggregation rule" — every join Message renders as its own
   * `social_join` row regardless of how many a single actor has.
   */
  private const AGGREGABLE_TEMPLATES = [
    'activity_post_created',
    'activity_comment_created',
  ];

  /**
   * Maps a single (count=1) aggregable-template Message to its row type.
   */
  private const SINGLE_ROW_TYPE = [
    'activity_post_created' => 'content_card',
    'activity_comment_created' => 'social_comment',
  ];

  /**
   * Constructs a new ActivityFeedController.
   *
   * The property is deliberately named $entityTypeManagerService (NOT
   * $entityTypeManager) — {@see ControllerBase} already declares its own
   * non-readonly `protected $entityTypeManager` property (lazily populated
   * by its own entityTypeManager() accessor); a constructor-promoted
   * `readonly` property of the SAME name fatals at class-load
   * ("Cannot redeclare non-readonly property ... as readonly").
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly ActivityAggregator $aggregator,
    private readonly ActivityRowBuilder $rowBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('do_activity_feed.aggregator'),
      $container->get('do_activity_feed.row_builder'),
    );
  }

  /**
   * Route callback for `/activity` (my_groups scope).
   *
   * @return array
   *   The feed render array.
   */
  public function myGroups(): array {
    return $this->renderFeed('my_groups');
  }

  /**
   * Route callback for `/activity/group/{group}` (single-group scope).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to scope the feed to (upcast by the route; group `view`
   *   access is already enforced by the route's `_entity_access:
   *   'group.view'` requirement before this method ever runs).
   *
   * @return array
   *   The feed render array.
   */
  public function groupScope(GroupInterface $group): array {
    return $this->renderFeed('group', $group);
  }

  /**
   * Title callback for the group-scope route.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   *
   * @return string
   *   The page title.
   */
  public function groupScopeTitle(GroupInterface $group): string {
    return (string) $this->t('Activity: @group', ['@group' => $group->label()]);
  }

  /**
   * Builds the feed render array for a given scope.
   *
   * The exact contract survey.md's "Forward-compat check" commits to for
   * ST-8 (#130) reuse — a caller outside this controller (a future story's
   * own controller) may call this directly.
   *
   * @param string $scope
   *   Either 'my_groups' (the current viewer's membership scope) or
   *   'group' (a single fixed group — requires $group).
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   (optional) The group to scope to, required when $scope === 'group'.
   *
   * @return array
   *   A render array:
   *   - '#theme': 'activity_feed'.
   *   - '#rows': the ordered list of row model arrays (see
   *     ActivityRowBuilder::buildRow()'s return-value docblock for the
   *     per-row shape; an aggregated row additionally carries 'count' and
   *     'children').
   *   - '#empty': bool, true when '#rows' is empty.
   *   - '#empty_copy': scope-appropriate empty-state copy string.
   *   - '#attached': the activity_feed CSS library.
   */
  public function renderFeed(string $scope, ?GroupInterface $group = NULL): array {
    $messages = $scope === 'group' && $group instanceof GroupInterface
      ? $this->loadMessagesForGroup($group)
      : $this->loadMessagesForMyGroups();

    $rows = $this->buildRows($messages);

    usort($rows, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);

    return [
      '#theme' => 'activity_feed',
      '#rows' => $rows,
      '#empty' => empty($rows),
      '#empty_copy' => $this->emptyCopy($scope),
      '#attached' => [
        'library' => ['do_activity_feed/activity_feed'],
      ],
    ];
  }

  /**
   * Loads surfacing-template Messages for the viewer's my_groups scope.
   *
   * Drives the `activity_feed` view's `default` display (carrying the
   * `do_activity_feed_membership_scope` filter) via the standard
   * Views-executable pattern
   * (mirrors {@see \Drupal\Tests\do_streams\Kernel\StreamsScopeTest::
   * executeDemo()}) — `$view->result[]->_entity` yields real, already-
   * loaded `Message` entities (EntityViewsData populates `_entity` for any
   * entity-derived base table, exactly as it does for `node_field_data`).
   *
   * @return \Drupal\message\MessageInterface[]
   *   Messages ordered `created` DESC, restricted to
   *   self::SURFACING_TEMPLATES and the viewer's membership scope.
   */
  private function loadMessagesForMyGroups(): array {
    $view = Views::getView('activity_feed');
    if ($view === NULL) {
      return [];
    }

    $view->setDisplay('default');
    $view->preExecute();
    $view->execute();

    $messages = [];
    foreach ($view->result as $row) {
      if (isset($row->_entity) && $row->_entity instanceof MessageInterface) {
        $messages[] = $row->_entity;
      }
    }
    return $messages;
  }

  /**
   * Loads the surfacing-template Messages for a single fixed group.
   *
   * Bypasses Views entirely (see
   * config/install/views.view.activity_feed.yml's own docblock) — a fixed
   * single-group condition is a plain EntityQuery, no contextual-argument
   * machinery needed. Group access itself is already enforced by the
   * route's `_entity_access: 'group.view'` requirement before this method
   * is ever reached.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to scope to.
   *
   * @return \Drupal\message\MessageInterface[]
   *   Messages ordered `created` DESC, restricted to
   *   self::SURFACING_TEMPLATES and this one group.
   */
  private function loadMessagesForGroup(GroupInterface $group): array {
    $storage = $this->entityTypeManagerService->getStorage('message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('template', self::SURFACING_TEMPLATES, 'IN')
      ->condition('field_group_id', $group->id())
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->execute();

    if (!$ids) {
      return [];
    }
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * Splits messages by template, aggregates the aggregable ones, builds rows.
   *
   * @param \Drupal\message\MessageInterface[] $messages
   *   Messages ordered `created` DESC (the query's own order — preserved
   *   per-template below since ActivityAggregator's own contract requires
   *   `created` DESC input).
   *
   * @return array
   *   The list of built row arrays (aggregated rows and single rows mixed,
   *   NOT yet re-sorted across templates — the caller, renderFeed(), sorts
   *   the combined list by `created` DESC).
   */
  private function buildRows(array $messages): array {
    $byTemplate = [];
    foreach ($messages as $message) {
      if (!$message instanceof MessageInterface) {
        continue;
      }
      $byTemplate[$message->bundle()][] = $message;
    }

    $rows = [];

    foreach ($byTemplate as $template => $templateMessages) {
      if (in_array($template, self::AGGREGABLE_TEMPLATES, TRUE)) {
        $rows = array_merge($rows, $this->buildAggregableRows($template, $templateMessages));
        continue;
      }

      // Non-aggregable surfacing template (activity_membership_created):
      // every Message becomes its own social_join row.
      foreach ($templateMessages as $message) {
        $row = $this->rowBuilder->buildRow($message, 'social_join');
        if ($row !== NULL) {
          $rows[] = $row;
        }
      }
    }

    return $rows;
  }

  /**
   * Aggregates one aggregable template's Messages and builds their rows.
   *
   * @param string $template
   *   The aggregable template id ('activity_post_created' or
   *   'activity_comment_created').
   * @param \Drupal\message\MessageInterface[] $messages
   *   That template's Messages, `created` DESC.
   *
   * @return array
   *   Built row arrays: one 'aggregated' row per bucket with count>=2, or
   *   the underlying single row type for a count===1 bucket. Buckets whose
   *   every member is access-dropped (buildRow() returns NULL for all of
   *   them) contribute no row at all; a partially-dropped bucket (some
   *   members viewable, some not) keeps only the viewable members and
   *   recomputes its own count/children from what remains.
   */
  private function buildAggregableRows(string $template, array $messages): array {
    $buckets = $this->aggregator->aggregate($messages);
    $rows = [];

    foreach ($buckets as $bucket) {
      if ($bucket['count'] === 1) {
        $singleMessage = $bucket['messages'][0];
        $row = $this->rowBuilder->buildRow($singleMessage, self::SINGLE_ROW_TYPE[$template]);
        if ($row !== NULL) {
          $rows[] = $row;
        }
        continue;
      }

      $row = $this->buildAggregatedRow($template, $bucket);
      if ($row !== NULL) {
        $rows[] = $row;
      }
    }

    return $rows;
  }

  /**
   * Builds one 'aggregated' row from a bucket with count>=2.
   *
   * Content-card members are access-checked individually (via
   * ActivityRowBuilder::buildRow() with the underlying single row type) so
   * an unviewable node's title never leaks into the aggregated summary's
   * `children` list — the brief's §Access "drop the row" rule applies at
   * the individual-member level even inside an aggregate.
   *
   * `actor_url`/`group_url` are picked up from the same per-member
   * `$memberRow` array `actor`/`group` already come from — see
   * ActivityRowBuilder::buildRow()'s docblock: it precomputes both URL
   * strings for every row it builds, single or aggregate-member alike. S's
   * Phase 9 spec audit (decisions.md, "S — Phase 9 REWORK", Defect 1) found
   * this method captured `actor`/`group` via `??=` but never did the
   * parallel capture for `actor_url`/`group_url`, so the returned row array
   * was missing both keys entirely — Twig's `{{ row.actor_url }}` /
   * `{{ row.group_url }}` then rendered as empty strings (`href=""`), a
   * WCAG 2.4.4 failure, on every LIVE aggregated row (10/10 on /activity,
   * 4/4 on /activity/group/6). Fixed by mirroring the exact `??=` pattern
   * already used for `actor`/`group` one line below.
   *
   * @param string $template
   *   The aggregable template id.
   * @param array $bucket
   *   One bucket array from ActivityAggregator::aggregate().
   *
   * @return array|null
   *   The 'aggregated' row array, or NULL if every member of the bucket was
   *   access-dropped (leaving nothing to aggregate).
   */
  private function buildAggregatedRow(string $template, array $bucket): ?array {
    $singleType = self::SINGLE_ROW_TYPE[$template];
    $children = [];
    $viewableMessages = [];
    $actor = NULL;
    $actorUrl = NULL;
    $group = NULL;
    $groupUrl = NULL;

    foreach ($bucket['messages'] as $message) {
      $memberRow = $this->rowBuilder->buildRow($message, $singleType);
      if ($memberRow === NULL) {
        // Brief §Access: an unviewable member is dropped from the
        // aggregate entirely, not merely hidden in the UI.
        continue;
      }
      $viewableMessages[] = $message;
      $actor ??= $memberRow['actor'];
      $actorUrl ??= $memberRow['actor_url'];
      $group ??= $memberRow['group'];
      $groupUrl ??= $memberRow['group_url'];
      $children[] = [
        'title' => $this->memberTitle($message, $memberRow),
        'url' => $this->memberUrl($message),
      ];
    }

    if (empty($viewableMessages)) {
      return NULL;
    }

    $newestMessage = $viewableMessages[0];

    return [
      'type' => 'aggregated',
      'message_id' => (int) $newestMessage->id(),
      'actor' => $actor,
      'actor_url' => $actorUrl,
      'group' => $group,
      'group_url' => $groupUrl,
      'referenced_entity_type' => NULL,
      'referenced_entity_id' => NULL,
      'created' => (int) $newestMessage->getCreatedTime(),
      'snippet' => NULL,
      'card' => NULL,
      'count' => count($viewableMessages),
      'template' => $template,
      'children' => $children,
    ];
  }

  /**
   * Resolves the display title for one aggregated-row child link.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The underlying Message.
   * @param array $memberRow
   *   That Message's already-built single-row array.
   *
   * @return string
   *   The referenced node's title (content_card members), or the comment
   *   snippet (social_comment members) when no node title applies.
   */
  private function memberTitle(MessageInterface $message, array $memberRow): string {
    if ($memberRow['type'] === 'content_card' && $memberRow['referenced_entity_type'] === 'node' && $memberRow['referenced_entity_id'] !== NULL) {
      $node = $this->entityTypeManagerService->getStorage('node')->load($memberRow['referenced_entity_id']);
      if ($node !== NULL) {
        return (string) $node->label();
      }
    }
    return (string) ($memberRow['snippet'] ?? $this->t('Untitled'));
  }

  /**
   * Resolves the canonical URL (a plain string) for an aggregated child link.
   *
   * Returns a plain string (not a \Drupal\Core\Url object) so
   * activity-row--aggregated.html.twig can print it directly into an
   * `href="{{ child.url }}"` attribute — Url has no `__toString()`,
   * matching this codebase's own node--stream-card.html.twig convention of
   * precomputing URL strings in the controller/preprocess layer rather than
   * passing Url objects into Twig.
   *
   * @param \Drupal\message\MessageInterface $message
   *   The underlying Message.
   *
   * @return string|null
   *   The referenced node's canonical URL string, or NULL when not
   *   resolvable.
   */
  private function memberUrl(MessageInterface $message): ?string {
    if (!$message->hasField('field_referenced_entity_type') || (string) $message->get('field_referenced_entity_type')->value !== 'node') {
      return NULL;
    }
    $nid = $message->hasField('field_referenced_entity_id') ? $message->get('field_referenced_entity_id')->value : NULL;
    if ($nid === NULL) {
      return NULL;
    }
    $node = $this->entityTypeManagerService->getStorage('node')->load($nid);
    return $node !== NULL ? $node->toUrl()->toString() : NULL;
  }

  /**
   * Returns scope-appropriate empty-state copy.
   *
   * Per the approved wireframe's State 2 annotation: truthful, naming the
   * concrete prerequisite (join a group) for my_groups scope rather than a
   * generic "no results".
   *
   * @param string $scope
   *   The requested scope ('my_groups' or 'group').
   *
   * @return string
   *   The empty-state copy string.
   */
  private function emptyCopy(string $scope): string {
    return $scope === 'group'
      ? (string) $this->t('No activity in this group yet.')
      : (string) $this->t("You're not a member of any group yet, so there's no activity to show. Join a group to see posts, comments, and updates from its members here.");
  }

}
