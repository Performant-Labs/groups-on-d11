<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * #253: N+1 regression guard for `groups_chrome_preprocess_node()`.
 *
 * Brief: docs/handoffs/253-preprocess-node-n1/brief.md. The hook calls
 * `$database->schema()->tableExists('comment_entity_statistics')` on every
 * `stream_card`-view-mode node render (survey.md: ~10x/page on `/`,
 * `/showcase`, `/stream` cold — see the PERF-4 audit,
 * docs/planning/perf/query-audit-2026-07-24.md). The table's existence
 * cannot change mid-request, so the check must be memoized per request
 * (brief: `drupal_static(__FUNCTION__ . '_has_comment_stats')`), not
 * re-issued on every node.
 *
 * Strategy (per handoff-A.md Finding #1 — T's call, either dialect-matching
 * `Database::startLog()` or a schema-decorator observer is an acceptable
 * PASS): this suite uses `Database::startLog()` + a MySQL/MariaDB-dialect
 * query-log filter, NOT a container-level Schema decorator. That choice was
 * verified empirically against this project's OWN test infrastructure,
 * not assumed:
 *  - `.github/workflows/test.yml` and `scripts/dev/run-kernel.sh` both set
 *    `SIMPLETEST_DB=mysql://...` for every kernel-test run in CI and in the
 *    local dev wrapper — there is no SQLite/PgSQL kernel-test lane anywhere
 *    in this project. The dialect-portability concern A raised (SQLite uses
 *    `sqlite_master`, PgSQL uses `pg_class`) does not apply here: MySQL/
 *    MariaDB's `information_schema.tables` shape (confirmed by reading the
 *    base `\Drupal\Core\Database\Schema::tableExists()`, which is NOT
 *    overridden by the mysql/mysqli drivers) is the only shape this suite
 *    will ever observe.
 *  - A full `database` service decorator was evaluated and rejected as
 *    disproportionately fragile for this assertion: `\Drupal\Core\Database\
 *    Connection` is abstract with a driver-specific constructor (no clean
 *    subclass point), and query-builder methods (`select()`, `query()`)
 *    return objects bound to `$this` — a `__call`-forwarding proxy would
 *    silently diverge from the real connection identity the moment any
 *    downstream code compares `instanceof Connection` or relies on a method
 *    not present on the proxy. `Database::startLog()` is core's OWN
 *    supported query-observation API and needs no such shim.
 *
 * Layer choice: kernel, calling `groups_chrome_preprocess_node()` directly.
 * The function is a plain global PHP function (no OOP dispatch, no
 * theme-negotiation dependency in its body — it only makes `\Drupal::`
 * static service calls) defined in a `.theme` file that is never
 * autoloaded; it is `require_once`'d directly by path, matching how
 * Drupal's theme system itself loads `.theme` files, WITHOUT installing the
 * full `groups_chrome` theme (Olivero base + regions) as an active theme —
 * that would add real fragility (theme negotiation, region/library
 * bootstrapping) to pin a database-call-count contract that has nothing to
 * do with theme negotiation. This is the cheapest tier that can actually
 * invoke the real, unmodified hook body.
 *
 * RED reason (today, before F implements): `groups_chrome_preprocess_node()`
 * calls `tableExists('comment_entity_statistics')` unconditionally on every
 * invocation — no `drupal_static()` guard exists yet. Rendering 10
 * `stream_card` nodes therefore issues 10 `information_schema.tables`
 * queries for that table, not 1.
 *
 * @group do_chrome
 */
#[RunTestsInSeparateProcesses]
final class PreprocessNodeTableExistsCacheTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'comment',
    'datetime',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('comment', ['comment_entity_statistics']);

    // groups_chrome_preprocess_node() calls date.formatter->format($created,
    // 'medium'), which resolves the 'medium' DateFormat config entity
    // (core.date_format.medium) — installed by the 'system' module's own
    // config/install, matching the established fix for this exact error in
    // ActivityFeedRowRenderTest / StreamSwitcherHooksTest / StreamsShellTest
    // / MyEventsViewTest (all hit "Call to a member function getPattern() on
    // null" without this line).
    $this->installConfig(['system']);

    NodeType::create(['type' => 'post', 'name' => 'Post'])->save();

    // Load the theme's preprocess function directly (see class docblock —
    // this is a bare `.theme` file, never autoloaded). Guarded so re-running
    // this test within the same process (PHPUnit process isolation aside)
    // never triggers a "cannot redeclare function" fatal.
    if (!function_exists('groups_chrome_preprocess_node')) {
      require_once $this->themeFilePath();
    }
  }

  /**
   * Resolves the absolute path to `groups_chrome.theme`.
   *
   * The theme lives OUTSIDE the assembled `web/modules/custom` tree this
   * test itself is assembled into — it is tracked directly under
   * `web/themes/custom/groups_chrome/` in both the source checkout and CI's
   * assembled layout (assemble-config.sh only copies `docs/groups/modules/`
   * into `web/modules/custom/`; themes are never relocated). Resolved via
   * the container's `app.root` parameter rather than a source-relative
   * `__DIR__` climb, so it holds in both layouts.
   *
   * @return string
   *   Absolute path to groups_chrome.theme.
   */
  private function themeFilePath(): string {
    $root = $this->container->getParameter('app.root');
    $path = $root . '/themes/custom/groups_chrome/groups_chrome.theme';
    $this->assertFileExists($path, 'groups_chrome.theme must exist at the expected tracked-theme path.');
    return $path;
  }

  /**
   * Builds the `stream_card`-view-mode `$variables` array for one node.
   *
   * Mirrors the minimal shape `groups_chrome_preprocess_node()` reads:
   * `view_mode` (the early-return gate) and `node` (an entity implementing
   * NodeInterface).
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node being "rendered".
   *
   * @return array
   *   The $variables array, by reference contract (preprocess hooks mutate
   *   in place), suitable for passing to
   *   groups_chrome_preprocess_node(array &$variables).
   */
  private function streamCardVariables(Node $node): array {
    return [
      'view_mode' => 'stream_card',
      'node' => $node,
    ];
  }

  /**
   * Rendering 10 stream_card nodes issues exactly ONE
   * `tableExists('comment_entity_statistics')` query, not one per node.
   *
   * This is the brief's literal acceptance criterion #3: "asserts count of
   * `information_schema.tables ... comment_entity_statistics` queries == 1
   * after 10 renders."
   */
  public function testTableExistsCheckedOnceAcrossTenStreamCardRenders(): void {
    $nodes = [];
    for ($i = 0; $i < 10; $i++) {
      $node = Node::create([
        'type' => 'post',
        'title' => 'Stream post ' . $i,
      ]);
      $node->save();
      $nodes[] = $node;
    }

    Database::startLog('preprocess_node_table_exists_count');

    foreach ($nodes as $node) {
      $variables = $this->streamCardVariables($node);
      groups_chrome_preprocess_node($variables);
    }

    $queries = Database::getLog('preprocess_node_table_exists_count');

    $table_exists_queries = array_filter($queries, static function (array $log_entry): bool {
      // Base \Drupal\Core\Database\Schema::tableExists() issues
      // `SELECT 1 FROM information_schema.tables WHERE ("table_schema" = ?)
      // AND ("table_name" = ?) AND ("table_type" = ?)` — the table NAME is
      // a bound placeholder argument, not inlined into the query string (see
      // web/core/lib/Drupal/Core/Database/Schema.php:204 + Condition's
      // parameterized build). Verified empirically against this suite's own
      // debug query-log dump before authoring this filter (a naive
      // str_contains() on $log_entry['query'] alone can never match the
      // table name — it is always a placeholder). The bound value is also
      // PREFIXED by Schema::buildTableNameCondition() -> getPrefixInfo()
      // (kernel tests run against a randomly-prefixed test database, e.g.
      // "test53310640comment_entity_statistics", not the bare table name)
      // — confirmed empirically the same way, so the arg match below uses
      // str_ends_with(), not an exact match. Match on the query SHAPE
      // (information_schema.tables) AND a bound arg ending in the bare
      // table name, so this can't accidentally count an unrelated
      // information_schema query for a different table.
      if (!str_contains($log_entry['query'], 'information_schema.tables')) {
        return FALSE;
      }
      foreach ($log_entry['args'] as $value) {
        if (is_string($value) && str_ends_with($value, 'comment_entity_statistics')) {
          return TRUE;
        }
      }
      return FALSE;
    });

    $this->assertCount(
      1,
      $table_exists_queries,
      sprintf(
        "Expected exactly 1 tableExists('comment_entity_statistics') query across 10 stream_card node renders (per-request memoization), but observed %d. Queries seen: %s",
        count($table_exists_queries),
        implode(' | ', array_column($table_exists_queries, 'query'))
      )
    );

    // Guard against a vacuous pass: if the filter matched ZERO queries (e.g.
    // because the query shape drifted, or the table check was skipped
    // entirely), that is not evidence of correct memoization either — it
    // must have been checked at least once to populate gc_stream.comment_count.
    $this->assertGreaterThanOrEqual(
      1,
      count($table_exists_queries),
      'The tableExists(\'comment_entity_statistics\') check must still run at least once — a count of 0 would mean the check was skipped entirely, not memoized.'
    );
  }

  /**
   * Sanity check: the memoized comment-count path still resolves the real
   * aggregate for a node that HAS a comment_entity_statistics row, proving
   * the count-of-1 assertion above is not merely tolerating a broken/no-op
   * check. Brief AC #2: "Comment count still populates correctly."
   */
  public function testCommentCountStillPopulatesFromExistingStatisticsRow(): void {
    $node = Node::create([
      'type' => 'post',
      'title' => 'Node with a comment',
    ]);
    $node->save();

    $this->container->get('database')->insert('comment_entity_statistics')
      ->fields([
        'entity_id' => $node->id(),
        'entity_type' => 'node',
        'field_name' => 'comment',
        'cid' => 0,
        'last_comment_timestamp' => \Drupal::time()->getRequestTime(),
        'last_comment_name' => NULL,
        'last_comment_uid' => 0,
        'comment_count' => 4,
      ])
      ->execute();

    $variables = $this->streamCardVariables($node);
    groups_chrome_preprocess_node($variables);

    $this->assertSame(
      4,
      $variables['gc_stream']['comment_count'],
      'gc_stream.comment_count must still resolve the real comment_entity_statistics aggregate once memoization is added.'
    );
  }

}
