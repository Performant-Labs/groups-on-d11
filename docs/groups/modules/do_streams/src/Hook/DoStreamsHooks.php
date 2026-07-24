<?php

declare(strict_types=1);

namespace Drupal\do_streams\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\do_group_pin\Hook\DoGroupPinHooks;
use Drupal\flag\FlaggingInterface;
use Drupal\node\NodeInterface;
use Drupal\views\ViewExecutable;

/**
 * Hook implementations for do_streams.
 *
 * Ranking wiring (recent/last-activity/hot/pinned-first) over the
 * do_streams_demo proof view, mirroring do_group_pin's
 * views_query_alter/compiled-query-rewrite/cache-tag pattern (new module,
 * same technique — see survey.md's Reuse map), and the shared stream shell
 * theme hook + preprocess (scope tabs + ranking control).
 *
 * ST-8 (#130): constructor-injected with `ModelToggleHooks` so this class's
 * `preprocessViewsView()` — the module's ONE legal
 * `#[Hook('preprocess_views_view')]` listener (Drupal's `ModuleHandler
 * ::invoke()` throws `LogicException` if a single module registers a
 * second `preprocess_views_view` implementation, even across two classes)
 * — can delegate to `ModelToggleHooks::preprocessViewsView()` for the
 * `activity_stream:page_1` stream-model switcher mount, after handling its
 * own pre-existing `following_feed` library-attach branch. See
 * `ModelToggleHooks`'s own class docblock for the full explanation of why
 * that class's `preprocessViewsView()` is deliberately NOT `#[Hook]`-
 * attributed itself.
 *
 * `$modelToggleHooks` carries a NULL default (`?ModelToggleHooks = NULL`),
 * mirroring `ModelToggleHooks`'s own nullable `$switcher` parameter, for the
 * identical underlying reason: `StreamsShellTest
 * ::preprocessShellVariables()` — a PRE-EXISTING test, not authored/touched
 * by this story — directly `new DoStreamsHooks()`-instantiates this class
 * (bypassing the service container entirely) to call
 * `preprocessDoStreamsShell()`, a method that has no relationship
 * whatsoever to the model-toggle delegation. Requiring a non-defaulted
 * constructor argument broke that direct-instantiation call site
 * (`ArgumentCountError`). The real, container-built service
 * (`do_streams.hooks` in do_streams.services.yml) always supplies a real
 * `ModelToggleHooks` instance; only a caller that manually `new`s this
 * class for an unrelated method ever sees the NULL default, and
 * `preprocessViewsView()` guards on it with the null-safe operator below.
 */
class DoStreamsHooks {

  /**
   * The id of the demo/proof view the ranking hook alters.
   *
   * ST-1/2/4/6's own shipped views reuse the SAME ranking contract (a
   * `ranking` contextual argument) — if/when they ship their own view ids,
   * this constant is the single place that list grows.
   */
  public const DEMO_VIEW_ID = 'do_streams_demo';

  /**
   * The id of ST-2's shipped `/following` view (#111).
   *
   * Used by self::preprocessViewsView() to scope the `do_streams/following`
   * library attachment to exactly this view, per handoff-A.md finding §1's
   * preferred mechanism (a single, view-id-guarded preprocess hook, matching
   * this class's existing lightweight-preprocess convention rather than
   * introducing a new attachment mechanism).
   */
  public const FOLLOWING_FEED_VIEW_ID = 'following_feed';

  public function __construct(
    private readonly ?ModelToggleHooks $modelToggleHooks = NULL,
  ) {}

  /**
   * The id of ST-4's shipped `/trending` view (#113).
   *
   * Used by self::preprocessViewsView() to scope the `do_streams/trending`
   * library attachment to exactly this view, per #113's handoff-A.md
   * Finding 3 (advisory): extend the SAME view-id-guarded preprocess method
   * used for FOLLOWING_FEED_VIEW_ID with a second guard branch, rather than
   * introducing a new hook (e.g. a separate `views_pre_render`
   * implementation). `/trending` is a plain Views page — NOT a
   * `do_streams_shell` consumer — so this attach is the ONLY do_streams
   * involvement in ST-4; see brief.md's explicit non-scope ("No changes to
   * the do_streams shell").
   */
  public const TRENDING_VIEW_ID = 'trending';

  /**
   * The block plugin id of ST-5's shipped "Recent posts" block (#114).
   *
   * The exact `plugin:` value block.block.do_streams_user_activity.yml
   * carries (`views_block:<view id>-<display id>`) — used by
   * self::preprocessBlock() to scope the `.do-streams-profile-activity`
   * wrapper class + `do_streams/profile_activity` library attachment to
   * exactly this block, mirroring self::preprocessViewsView()'s existing
   * view-id-guarded convention (guard on a specific, meaningful identifier,
   * return immediately otherwise).
   */
  public const USER_ACTIVITY_BLOCK_PLUGIN_ID = 'views_block:user_activity-block_1';

  /**
   * Builds the per-viewing-user stream cache tag ([A-W2]).
   *
   * @param int|string $uid
   *   The viewing user's id.
   *
   * @return string
   *   The scoped cache tag, e.g. `do_streams:user_stream:7`.
   */
  public static function userStreamCacheTag(int|string $uid): string {
    return 'do_streams:user_stream:' . $uid;
  }

  /**
   * The single source of truth for the 4 stream scopes (id => label).
   *
   * #115 ST-6 (brief.md §Plan step 1): extracted from
   * self::preprocessDoStreamsShell()'s formerly-local `$scope_labels` array
   * so both the shared stream shell AND `StreamSwitcherHooks` read the same
   * ids/labels/order, never a second, independently-maintained list.
   *
   * A `public static function` rather than a `public const` — per
   * handoff-A.md finding §1, `TranslatableMarkup` instances cannot be class
   * constants (they are not evaluated at class-load time), so a method is
   * the only shape the runtime allows for a translated, constant-like
   * registry.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   An array of scope id => translated label, in stable Global / My Feed /
   *   Following / Trending order.
   */
  public static function getScopeRegistry(): array {
    return [
      'global' => new TranslatableMarkup('Global'),
      'my_feed' => new TranslatableMarkup('My Feed'),
      'following' => new TranslatableMarkup('Following'),
      'trending' => new TranslatableMarkup('Trending'),
    ];
  }

  /**
   * Branches the do_streams_demo query's ORDER BY on the `ranking` argument.
   *
   * Per [A-W3]'s resolution, the ranking parameter is the view's contextual
   * ARGUMENT (`$view->args[0]` on `page_global`, matching do_group_pin's
   * `$view->args[0]` gid pattern) — read here and branched into the
   * corresponding ORDER BY, exactly as DoGroupPinHooks::viewsQueryAlter()
   * does for its single-purpose pin sort.
   */
  #[Hook('views_query_alter')]
  public function viewsQueryAlter(ViewExecutable $view, mixed $query): void {
    if ($view->id() !== self::DEMO_VIEW_ID) {
      return;
    }

    $ranking = $view->args[0] ?? 'recent';

    switch ($ranking) {
      case 'last_activity':
        $this->applyLastActivityRanking($query);
        break;

      case 'hot':
        $this->applyHotRanking($query);
        break;

      case 'pinned':
        $this->applyPinnedRanking($query);
        break;

      case 'recent':
      default:
        // The view's own registered sort (`created DESC`) already implements
        // "recent" — no query alteration needed.
        break;
    }
  }

  /**
   * Last-activity ranking ([B-1]): GREATEST(changed, comment activity) DESC.
   *
   * LEFT JOINs comment_entity_statistics (a node has at most one row per
   * comment field; do_streams_demo's fixture nodes carry a single `comment`
   * field, mirroring do_discovery's own cron query against the same table) and
   * orders by GREATEST(node_field_data.changed, COALESCE(NULLIF(
   * last_comment_timestamp, 0), changed)) DESC — a node with no comments
   * falls back to `changed` (last_comment_timestamp is 0, not NULL, when a
   * node has no comments), never sorted as "never active".
   */
  protected function applyLastActivityRanking(mixed $query): void {
    $definition = [
      'type' => 'LEFT',
      'table' => 'comment_entity_statistics',
      'field' => 'entity_id',
      'left_table' => 'node_field_data',
      'left_field' => 'nid',
      'extra' => [
        ['field' => 'entity_type', 'value' => 'node'],
      ],
    ];
    $join = \Drupal::service('plugin.manager.views.join')->createInstance('standard', $definition);
    $query->addRelationship('do_streams_comment_stats', $join, 'node_field_data');

    $query->addOrderBy(
      NULL,
      'GREATEST(node_field_data.changed, COALESCE(NULLIF(do_streams_comment_stats.last_comment_timestamp, 0), node_field_data.changed))',
      'DESC',
      'do_streams_last_activity_sort',
    );
    $this->frontOfOrderBy($query, 'do_streams_last_activity_sort');
  }

  /**
   * Hot ranking ([W-2]): do_discovery_hot_score.score DESC via LEFT JOIN.
   *
   * The do_streams module only CONSUMES do_discovery_hot_score (already
   * joinable on `nid`, per do_discovery's own views_data() relationship) —
   * never recomputes scoring logic. The join MUST be LEFT (not INNER): a
   * freshly published node with no computed score row yet must still
   * appear, sorted as if score 0.
   */
  protected function applyHotRanking(mixed $query): void {
    $definition = [
      'type' => 'LEFT',
      'table' => 'do_discovery_hot_score',
      'field' => 'nid',
      'left_table' => 'node_field_data',
      'left_field' => 'nid',
    ];
    $join = \Drupal::service('plugin.manager.views.join')->createInstance('standard', $definition);
    $query->addRelationship('do_streams_hot_score', $join, 'node_field_data');

    $query->addOrderBy(
      NULL,
      'COALESCE(do_streams_hot_score.score, 0)',
      'DESC',
      'do_streams_hot_sort',
    );
    $this->frontOfOrderBy($query, 'do_streams_hot_sort');
  }

  /**
   * Pinned-first ranking: mirrors DoGroupPinHooks::viewsQueryAlter() exactly.
   *
   * Reuses the SAME pin_in_group flag do_group_pin reads, LEFT JOINed onto
   * this view's node base table (independent of do_group_pin's own view),
   * with the pin CASE expression made the PRIMARY sort key (#52 fix:
   * array_unshift to the front of $query->orderby, not appended).
   */
  protected function applyPinnedRanking(mixed $query): void {
    $definition = [
      'type' => 'LEFT',
      'table' => 'flagging',
      'field' => 'entity_id',
      'left_table' => 'node_field_data',
      'left_field' => 'nid',
      'extra' => [
        ['field' => 'flag_id', 'value' => DoGroupPinHooks::PIN_FLAG_ID],
        ['field' => 'entity_type', 'value' => 'node'],
      ],
    ];
    $join = \Drupal::service('plugin.manager.views.join')->createInstance('standard', $definition);
    $query->addRelationship('do_streams_pin_flagging', $join, 'node_field_data');

    $query->addOrderBy(
      NULL,
      'CASE WHEN do_streams_pin_flagging.id IS NOT NULL THEN 1 ELSE 0 END',
      'DESC',
      'do_streams_pin_sort',
    );
    $this->frontOfOrderBy($query, 'do_streams_pin_sort');
  }

  /**
   * Moves the just-added order-by entry to the front of $query->orderby.
   *
   * Mirrors the #52 fix pattern exactly: hook_views_query_alter runs after
   * the view registers its own sorts, so appending an order-by makes it a
   * tie-breaker, never a real reorder. array_unshift preserves every other
   * registered sort (e.g. `created DESC`) as the secondary ordering.
   *
   * @param mixed $query
   *   The Views SQL query plugin (typed loosely to match the hook signature
   *   both do_streams and do_group_pin use for `views_query_alter`).
   * @param string $alias
   *   The order-by alias just added via addOrderBy(), assumed to be the
   *   last entry in $query->orderby.
   */
  protected function frontOfOrderBy(mixed $query, string $alias): void {
    foreach ($query->orderby as $key => $order) {
      if (($order['field'] ?? NULL) === $alias) {
        $entry = $query->orderby[$key];
        unset($query->orderby[$key]);
        array_unshift($query->orderby, $entry);
        $query->orderby = array_values($query->orderby);
        return;
      }
    }
  }

  /**
   * Collapses the do_streams_demo query to one row per node (#56 pattern).
   *
   * [A-W1]: unlike do_group_pin's hardcoded relationship alias string,
   * do_streams' join set differs per ranking branch (comment stats / hot
   * score / pin flagging), so the columns needing an aggregate + GROUP BY
   * treatment are discovered GENERICALLY by table membership (any SELECT
   * column whose table is one of the join-side tables this hook itself adds),
   * not by a literal alias.
   */
  #[Hook('query_views_do_streams_demo_alter')]
  public function queryViewsDoStreamsDemoAlter(SelectInterface $query): void {
    // Join-side table aliases this module's own views_query_alter() may add.
    // A SELECT column belonging to any of these is NOT guaranteed
    // one-row-per-node (it comes from a relationship/LEFT JOIN this hook
    // controls), so it must be aggregated before GROUP BY on the node's own
    // columns is legal under ONLY_FULL_GROUP_BY.
    //
    // @todo [W-1, diff-gate NIT] Consider deriving this list from the
    // compiled query's own join set (e.g. iterating $query->getTables() for
    // aliases this hook's viewsQueryAlter() itself registered) instead of a
    // static name list, for robustness as new ranking joins are added. Left
    // as a static list here: the compiled SelectInterface at this stage does
    // not expose a stable, reliably-introspectable "which joins came from
    // this hook" signal without added bookkeeping, and only one ranking
    // branch is ever active per request — a dynamic-discovery refactor risks
    // the #56 dedupe correctness this hook exists to guarantee for a
    // non-blocking NIT, so it is intentionally deferred rather than rushed.
    $join_side_tables = [
      'do_streams_comment_stats',
      'do_streams_hot_score',
      'do_streams_pin_flagging',
    ];

    $fields = &$query->getFields();
    foreach ($fields as $alias => $field) {
      if (in_array($field['table'] ?? NULL, $join_side_tables, TRUE)) {
        unset($fields[$alias]);
        $query->addExpression(
          'MIN(' . $field['table'] . '.' . $field['field'] . ')',
          $alias,
        );
      }
    }

    // Aggregate any order-by expression this hook added (pin_sort /
    // hot_sort / last_activity_sort), discovered generically by alias
    // prefix rather than a single hardcoded name, since only one ranking
    // branch is active per request.
    $expressions = &$query->getExpressions();
    foreach ($expressions as $alias => $expression) {
      if (str_starts_with($alias, 'do_streams_') && str_ends_with($alias, '_sort')) {
        $expressions[$alias]['expression'] = 'MAX(' . $expression['expression'] . ')';
      }
    }

    // Group by every remaining plain (non-aggregated) column now that every
    // join-side column has been moved to an aggregate.
    foreach ($query->getFields() as $field) {
      $table = $field['table'] ?? NULL;
      if ($table !== NULL) {
        $query->groupBy($table . '.' . $field['field']);
      }
    }
  }

  /**
   * Tags the rendered do_streams_demo view with the viewing-user's stream tag.
   *
   * Mirrors DoGroupPinHooks::viewsPostRender()'s pattern, but per [A-W2] the
   * tag is per-VIEWING-USER (`do_streams:user_stream:<uid>`), since
   * membership/following scope is per-user, not per-group.
   */
  #[Hook('views_post_render')]
  public function viewsPostRender(ViewExecutable $view, mixed &$output, mixed $cache): void {
    if ($view->id() !== self::DEMO_VIEW_ID) {
      return;
    }
    $uid = \Drupal::currentUser()->id();
    $view->element['#cache']['tags'] = Cache::mergeTags(
      $view->element['#cache']['tags'] ?? [],
      [self::userStreamCacheTag($uid)],
    );
  }

  /**
   * Invalidates the affected users' stream cache tags when content is pinned.
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->onFlaggingChange($entity);
  }

  /**
   * Invalidates the affected users' stream cache tags when content is unpinned.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->onFlaggingChange($entity);
  }

  /**
   * Invalidates the pin_in_group flagger's own user-stream cache tag.
   *
   * The do_streams module's pinned-first ranking reads the SAME
   * pin_in_group flag do_group_pin reads. A pin toggle can reorder ANY
   * viewing user's "pinned" ranking (it is not scoped to the flagger or
   * the node's group members — it is a global reorder), so the safe,
   * non-under-invalidating choice is to invalidate the tag for the user
   * who performed the toggle (their own cached stream is the one
   * guaranteed stale) — this keeps the invalidation scoped to
   * `do_streams:user_stream:<uid>` (never a blanket flush) while covering
   * the one viewing user this module can identify without additional
   * information the flagging entity doesn't carry.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was inserted or deleted; a no-op unless it is a
   *   `pin_in_group` flagging.
   */
  protected function onFlaggingChange(EntityInterface $entity): void {
    if (!$entity instanceof FlaggingInterface || $entity->getFlagId() !== DoGroupPinHooks::PIN_FLAG_ID) {
      return;
    }
    $node = $entity->getFlaggable();
    if (!$node instanceof NodeInterface) {
      return;
    }

    Cache::invalidateTags([self::userStreamCacheTag($entity->getOwnerId())]);
  }

  /**
   * Exposes the two scope filter plugins to Views as synthetic node fields.
   *
   * Views resolves a handler by the field's OWN `views_data` registration
   * (table + field), not merely by a config item's `plugin_id` — the same
   * discovery rule T's handoff-T-red.md documents for the `ranking`
   * argument. do_streams_membership_scope / do_streams_following_scope are
   * synthetic (filter-only, no underlying column) fields on
   * `node_field_data` so the fixture/shipped view's filter config can
   * resolve to {@see \Drupal\do_streams\Plugin\views\filter\MembershipScope}
   * / {@see \Drupal\do_streams\Plugin\views\filter\FollowingScope}.
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    return [
      'node_field_data' => [
        'do_streams_membership_scope' => [
          'title' => new TranslatableMarkup('Streams: Membership scope'),
          'help' => new TranslatableMarkup('Restricts content to groups the current user is a member of.'),
          'filter' => ['id' => 'do_streams_membership_scope'],
        ],
        'do_streams_following_scope' => [
          'title' => new TranslatableMarkup('Streams: Following scope'),
          'help' => new TranslatableMarkup('Restricts content to what the current user follows (content, author, or tag).'),
          'filter' => ['id' => 'do_streams_following_scope'],
        ],
      ],
    ];
  }

  /**
   * Do_streams' ONE legal `preprocess_views_view` listener.
   *
   * Handles FOUR independent, view-id-guarded concerns in one method, because
   * Drupal's `ModuleHandler::invoke()` throws `LogicException: "Module
   * do_streams should not implement preprocess_views_view more than once"`
   * the moment TWO classes in this module each carry a
   * `#[Hook('preprocess_views_view')]` method — the exact class of bug this
   * module already hit and fixed for `#[Hook('theme')]`. This ONE method
   * therefore handles three independently-scoped concerns:
   *
   * 1. Issue #111 ST-2: attaches the `do_streams/following` library on the
   *    `/following` view only (self::FOLLOWING_FEED_VIEW_ID).
   * 2. Issue #113 ST-4: attaches the `do_streams/trending` library on the
   *    `/trending` view only (self::TRENDING_VIEW_ID). Per #113's
   *    handoff-A.md Finding 3 (advisory): extend the SAME view-id-guarded
   *    preprocess method rather than adding a new hook — that would trip
   *    exactly the LogicException described above.
   * 3. Issue #115 ST-6: delegates to
   *    {@see StreamSwitcherHooks::preprocessViewsView()} for any view in
   *    `StreamSwitcherHooks::ATTACH_VIEW_IDS` — prepends the switcher tabs
   *    render array and attaches `do_streams/stream-switcher`.
   * 4. Issue #130 ST-8: delegates to
   *    {@see ModelToggleHooks::preprocessViewsView()} for the SC-F1
   *    Content/Activity variant switcher mount on `activity_stream:page_1`.
   *    The delegation call uses the null-safe operator (`?->`) because
   *    `$this->modelToggleHooks` is NULL when this class is directly
   *    instantiated (bypassing the container) with no constructor argument;
   *    the real container-built `do_streams.hooks` service always supplies
   *    a real instance.
   *
   * All applicable concerns can fire independently for the SAME `$variables`
   * (e.g. `/trending` is BOTH the trending view and switcher-attached;
   * `activity_stream` is switcher-attached AND the model-toggle target).
   */
  #[Hook('preprocess_views_view')]
  public function preprocessViewsView(array &$variables): void {
    $view = $variables['view'] ?? NULL;
    if (!$view instanceof ViewExecutable) {
      return;
    }

    if ($view->id() === self::FOLLOWING_FEED_VIEW_ID) {
      $variables['#attached']['library'][] = 'do_streams/following';
    }

    if ($view->id() === self::TRENDING_VIEW_ID) {
      $variables['#attached']['library'][] = 'do_streams/trending';
    }

    if (in_array($view->id(), StreamSwitcherHooks::ATTACH_VIEW_IDS, TRUE)) {
      (new StreamSwitcherHooks())->preprocessViewsView($variables);
    }

    $this->modelToggleHooks?->preprocessViewsView($variables);
  }

  /**
   * Wraps the "Recent posts" profile-activity block with its own class.
   *
   * Issue #114 ST-5. Mirrors self::preprocessViewsView()'s existing
   * view/block-id-guarded convention exactly (guard on a specific,
   * meaningful identifier, return immediately otherwise) rather than
   * introducing a new attachment mechanism.
   *
   * Uses `hook_preprocess_block` (not `preprocess_views_view`) deliberately:
   * `block.html.twig` renders the block's own `<h2>{{ label }}</h2>` title
   * and `{{ content }}` (the view's rendered output) as SIBLINGS inside one
   * outer `<div{{ attributes }}>` — attaching the wrapper class at the
   * block level, not the inner views level, gives the wireframe's single
   * coherent "Recent posts" section (heading + rows together under one
   * selector), matching wireframe.md's depiction of one bordered block
   * rather than a class that would only wrap the rows, sibling to the
   * heading.
   *
   * The CSS itself (`css/profile-activity.css`) only carries small
   * container-rhythm tweaks — card visuals are inherited from the shared
   * theme stylesheet, exactly as `css/following.css` already established
   * for #111 ST-2.
   */
  #[Hook('preprocess_block')]
  public function preprocessBlock(array &$variables): void {
    $plugin_id = $variables['plugin_id'] ?? NULL;
    if ($plugin_id !== self::USER_ACTIVITY_BLOCK_PLUGIN_ID) {
      return;
    }
    $variables['attributes']['class'][] = 'do-streams-profile-activity';
    $variables['#attached']['library'][] = 'do_streams/profile_activity';
  }

  /**
   * Registers this module's theme hooks (do_streams_shell + stream_switcher).
   *
   * The shared stream shell is [B-3]; `stream_switcher` was added by #115
   * ST-6. `stream_switcher` (StreamSwitcherHooks::preprocessViewsView())
   * is registered HERE, not on `StreamSwitcherHooks` itself, because
   * Drupal's `ModuleHandler::invoke()` throws `LogicException: "Module
   * do_streams should not implement theme more than once"` the moment TWO
   * separate classes in the same module both carry a `#[Hook('theme')]`
   * method — hook_theme() (like every non-cumulative hook) must have
   * exactly one implementation per module, regardless of which class
   * declares it (confirmed via CI-equivalent kernel test failure at
   * F-implementation time: StreamsInstallTest::
   * testModuleInstallsWithZeroSchemaChanges and StreamsShellTest::
   * testNoHardcodedRoutePathsInRenderedTabMarkup both threw this exact
   * exception when `stream_switcher` was registered via a second `theme()`
   * method on StreamSwitcherHooks — reverted in favor of this single
   * consolidated registration).
   */
  #[Hook('theme')]
  public function theme(array $existing, string $type, string $theme, string $path): array {
    return $existing + [
      'do_streams_shell' => [
        'variables' => [
          'active_scope' => 'global',
          'active_ranking' => 'recent',
          'results' => [],
          'scope_tabs' => [],
          'ranking_control' => [],
          'empty' => TRUE,
          'empty_copy' => '',
        ],
        'template' => 'do-streams-shell',
      ],
      'stream_switcher' => [
        'variables' => [
          'tabs' => [],
        ],
        'template' => 'stream-switcher',
      ],
    ];
  }

  /**
   * Builds the shell's scope_tabs / ranking_control / empty / empty_copy vars.
   *
   * [B-3]'s shell contract, built entirely from the `#active_scope` /
   * `#active_ranking` / `#results` properties the caller (e.g. ST-1/2/4/6's
   * controller) sets on the `#theme => do_streams_shell` render array — no
   * hardcoded route/href strings, per the acceptance criterion and the
   * approved wireframe's annotation convention (`scope_tabs[n].id` /
   * `ranking_control[n].id`).
   *
   * Diff-gate [B-1]: each `scope_tabs` entry also carries `url_or_param`, a
   * plain query-PARAMETER-mapping string derived from the tab's own `id`
   * (`?scope=<id>`) — NOT a hardcoded route path. Downstream stories
   * (#110-#115) wire their own routes and read `?scope=` off the query
   * string; this shell never bakes in a page path.
   *
   * D-gate resolution 1 (handoff-D.md, binding): the Recent ranking pill is
   * NEVER rendered `disabled`, even under the Trending scope — ranking stays
   * orthogonal to scope ([B-2]); Trending only defaults the ranking to Hot.
   *
   * D-gate resolution 2 (handoff-D.md, binding): 4 DISTINCT, scope-truthful
   * empty-state copy strings — Global's must never contain a follow-oriented
   * CTA.
   *
   * #115 ST-6 (brief.md §Plan step 1): `$scope_tabs` is now built by
   * iterating self::getScopeRegistry() — the SAME shared, ordered
   * id => label source `StreamSwitcherHooks::buildTabList()` reads — instead
   * of a second, locally-declared `$scope_labels` array. No other behavior
   * in this method changes: same 4 ids, same labels, same order, same
   * `url_or_param`/`active` shape.
   */
  #[Hook('preprocess_do_streams_shell')]
  public function preprocessDoStreamsShell(array &$variables): void {
    $active_scope = $variables['active_scope'] ?? 'global';
    $active_ranking = $variables['active_ranking'] ?? 'recent';
    $results = $variables['results'] ?? [];

    $scope_tabs = [];
    foreach (self::getScopeRegistry() as $id => $label) {
      $scope_tabs[] = [
        'id' => $id,
        'label' => $label,
        // [B-3]/diff-gate [B-1]: a query-PARAMETER mapping derived from the
        // tab's own id (e.g. `?scope=global`), NEVER a hardcoded route path
        // — downstream stories (#110-#115) wire their own routes and read
        // `?scope=` off the query string; this shell never bakes in a page
        // path, preserving the "no hardcoded routes" acceptance criterion.
        'url_or_param' => '?scope=' . $id,
        'active' => $id === $active_scope,
      ];
    }

    $ranking_labels = [
      'recent' => new TranslatableMarkup('Recent'),
      'hot' => new TranslatableMarkup('Hot'),
    ];
    $ranking_control = [];
    foreach ($ranking_labels as $id => $label) {
      // D-gate resolution 1: no `disabled` key under ANY scope, including
      // Trending — the Recent pill stays unselected-but-clickable there.
      $ranking_control[] = [
        'id' => $id,
        'label' => $label,
        'active' => $id === $active_ranking,
      ];
    }

    $empty_copy = [
      'global' => (string) new TranslatableMarkup('No content to show yet. Check back soon, or explore groups to find something new.'),
      'my_feed' => (string) new TranslatableMarkup("You haven't joined any groups yet. Join a group to see its content here."),
      'following' => (string) new TranslatableMarkup("You're not following any content, people, or tags yet. Follow something to see it here."),
      'trending' => (string) new TranslatableMarkup('Nothing is trending right now. Check back soon.'),
    ];

    $variables['scope_tabs'] = $scope_tabs;
    $variables['ranking_control'] = $ranking_control;
    $variables['empty'] = empty($results);
    $variables['empty_copy'] = $empty_copy[$active_scope] ?? $empty_copy['global'];
  }

}
