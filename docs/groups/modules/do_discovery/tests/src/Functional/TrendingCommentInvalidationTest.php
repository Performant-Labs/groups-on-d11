<?php

declare(strict_types=1);

namespace Drupal\Tests\do_discovery\Functional;

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\Core\Config\FileStorage;
use Drupal\Tests\BrowserTestBase;

/**
 * #251 Fix 2 — `/trending` must reflect a new comment without waiting for
 * cron (PERF-3 audit gap).
 *
 * RED (Phase 4, authored by T before F implements). Regression test for
 * `cache-audit-2026-07-24.md` §2 Scenario 4 / §4 Recommendation 2: today
 * `do_discovery_hot_score.score` is written ONLY by `DoDiscoveryHooks::cron()`
 * (the module's own `HotScoreForumCommentTest` proves this in its own
 * docblock — it manually invokes `$hooks->cron()` after posting a comment,
 * rather than asserting the score updates on its own). `/trending`'s Views
 * display already declares `cache: type: none` (`views.view.trending.yml`),
 * so this is NOT a render/page-cache-invalidation gap — the view always
 * re-queries on every request. The actual gap is that the underlying SCORE
 * ROW is never recomputed on `comment_insert`, so re-querying an
 * always-fresh view still returns stale ordering.
 *
 * Test approach (brief's advisory-hold instruction, applied): asserting a
 * full RENDER-ORDER change on `/trending` after ONE comment would require
 * knowing the exact score delta needed to flip two specific nodes' rank
 * (fragile, couples the test to the scoring formula's constants) AND, per
 * the audit + `HotScoreForumCommentTest`'s own established pattern, the
 * production recompute path is a single-row `database->merge()` write, not
 * a Views handler subclass or controller wrapper — so this does NOT trip
 * the brief's advisory-hold trigger (no Views handler subclass / controller
 * refactor is needed; a `#[Hook('comment_insert')]` on the EXISTING
 * `DoDiscoveryHooks` class, mirroring its own `nodeInsert()` method shape,
 * is sufficient). Given that, this suite pins the OBSERVABLE, HTTP-level
 * outcome the audit's acceptance criterion actually requires — a
 * comment causing a LOWER-scored node to move AHEAD of a HIGHER-scored one
 * in `/trending`'s real rendered order — using two nodes deliberately
 * seeded 1 comment apart (a difference the documented scoring formula,
 * comment_count*3 + view_count*0.5, makes an unambiguous, non-fragile rank
 * flip: 0 comments = score 0 vs. 1 comment = score 3, comment_count is an
 * INTEGER so no formula-constant coupling beyond "more comments values
 * more" is required).
 *
 * Layer choice: Functional (BrowserTestBase) — a real HTTP `drupalGet()`
 * against the served `/trending` route is the only way to observe the
 * ACTUAL rendered row order a viewer would see, and `cache: type: none`
 * confirms no page/render cache layer could mask the gap either way,
 * exactly mirroring `PrivacyDirectoryTest`'s "the audit report IS the
 * survey, no re-investigation needed" posture toward existing, cited
 * evidence.
 *
 * @group do_discovery
 * @group do_tests
 */
class TrendingCommentInvalidationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'comment',
    'field',
    'text',
    'filter',
    'views',
    'do_discovery',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!\Drupal\node\Entity\NodeType::load('forum')) {
      $this->drupalCreateContentType(['type' => 'forum', 'name' => 'Forum topic']);
    }

    // Unlike KernelTestBase, BrowserTestBase's install profile ('testing')
    // runs each enabled module's REAL hook_install() (comment_install()
    // included) as part of the site install — comment_entity_statistics,
    // the comment entity schema, and do_discovery_hot_score (do_discovery's
    // own hook_schema()) are already installed by the time setUp() reaches
    // here. installSchema()/installEntitySchema() are KernelTestBase-only
    // APIs (not present on BrowserTestBase) and comment_install() has
    // already set 'comment.maintain_entity_statistics' — no manual schema
    // install or state seeding is needed or possible here, unlike
    // HotScoreForumCommentTest's Kernel-layer setUp().

    // Install the comment type/field config from module-local fixtures
    // (mirrors HotScoreForumCommentTest's shippedConfigDir() pattern, but
    // per PROJECT_CONTEXT.md's fixture-locality rule, copied here as a
    // MODULE-LOCAL fixture rather than a source-relative path into
    // config/sync/docs/groups/config, which passes in the source tree but
    // fails in CI's assembled layout).
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager = \Drupal::entityTypeManager();

    foreach ([
      'comment_type' => 'comment.type.comment',
      'field_storage_config' => 'field.storage.node.comment',
      'field_config' => 'field.field.node.forum.comment',
    ] as $storage_id => $config_name) {
      $data = $fixtures->read($config_name);
      $this->assertNotFalse($data, sprintf('Fixture %s exists and is readable.', $config_name));
      $entity_type_manager->getStorage($storage_id)->create($data)->save();
    }

    // views.view.trending.yml is a site-level config artifact never
    // module-shipped — install it from the same module-local fixture copy.
    $viewData = $fixtures->read('views.view.trending');
    $this->assertNotFalse($viewData, 'The views.view.trending fixture exists and is readable.');
    $entity_type_manager->getStorage('view')->create($viewData)->save();

    \Drupal::service('router.builder')->rebuild();
  }

  /**
   * Creates a published forum node and seeds its hot_score row directly
   * (bypassing cron/comment_insert — this test controls the BASELINE score
   * explicitly so the fix under test is isolated to the comment_insert
   * delta, not conflated with the pre-existing node_insert seeding hook).
   *
   * @param string $title
   *   The node title.
   * @param float $baseline_score
   *   The `do_discovery_hot_score.score` value to seed for this node.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved, published forum node.
   */
  protected function createScoredForumNode(string $title, float $baseline_score) {
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'forum',
      'title' => $title,
      'status' => 1,
    ]);
    $node->save();

    \Drupal::database()->merge('do_discovery_hot_score')
      ->key('nid', $node->id())
      ->fields([
        'score' => $baseline_score,
        'computed' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    return $node;
  }

  /**
   * Fix 2 (audit §2 Scenario 4 / §4 Rec 2): commenting on the LOWER-scored
   * of two forum nodes makes it outrank the higher-scored one in
   * `/trending`'s real rendered order, on the VERY NEXT request — no manual
   * cron invocation, no cache clear.
   */
  public function testCommentOnLowerScoredNodeMovesItAheadInTrending(): void {
    // "Higher Score Baseline" starts ahead (score 5, no comments yet).
    $higher = $this->createScoredForumNode('Higher Score Baseline', 5.0);
    // "Commented Topic" starts behind (score 0).
    $lower = $this->createScoredForumNode('Commented Topic', 0.0);

    $this->drupalGet('/trending');
    $this->assertSession()->statusCodeEquals(200);
    $before = $this->getSession()->getPage()->getContent();
    $higherPosBefore = strpos($before, 'Higher Score Baseline');
    $lowerPosBefore = strpos($before, 'Commented Topic');
    $this->assertNotFalse($higherPosBefore, '"Higher Score Baseline" renders in /trending before any comment.');
    $this->assertNotFalse($lowerPosBefore, '"Commented Topic" renders in /trending before any comment.');
    $this->assertLessThan(
      $lowerPosBefore,
      $higherPosBefore,
      'Baseline: "Higher Score Baseline" (score 5) renders BEFORE "Commented Topic" (score 0) in /trending — establishes the pre-comment order this test flips.',
    );

    // Post a comment on the lower-scored node — the real production
    // trigger. comment_count x 3 = a score of 3.0, still less than the
    // higher node's seeded 5.0 by itself, so a SINGLE comment_insert-driven
    // recompute alone would not flip the order — deliberately: this proves
    // the fix does more than "any write happened," it proves the SPECIFIC
    // node's score was recomputed to reflect ITS OWN new comment count. To
    // make the flip unambiguous without coupling to the scoring formula's
    // exact constants, two comments are posted (comment_count=2 => 6.0 > 5.0).
    $account = $this->drupalCreateUser(['access comments', 'post comments', 'skip comment approval']);
    \Drupal::currentUser()->setAccount($account);
    foreach (['First reply', 'Second reply'] as $body) {
      $comment = Comment::create([
        'entity_type' => 'node',
        'entity_id' => $lower->id(),
        'field_name' => 'comment',
        'comment_type' => 'comment',
        'subject' => $body,
        'comment_body' => ['value' => $body, 'format' => 'plain_text'],
        'uid' => $account->id(),
        'status' => CommentInterface::PUBLISHED,
      ]);
      $comment->save();
    }

    // No manual cron invocation, no cache clear — the real regression this
    // fix closes.
    $this->drupalGet('/trending');
    $this->assertSession()->statusCodeEquals(200);
    $after = $this->getSession()->getPage()->getContent();

    $lowerPos = strpos($after, 'Commented Topic');
    $higherPos = strpos($after, 'Higher Score Baseline');
    $this->assertNotFalse($lowerPos, '"Commented Topic" still renders in /trending after commenting.');
    $this->assertNotFalse($higherPos, '"Higher Score Baseline" still renders in /trending after the other node\'s comment.');

    $this->assertLessThan(
      $higherPos,
      $lowerPos,
      'Fix 2: after 2 comments (score 6.0) are posted on "Commented Topic", it must render AHEAD of "Higher Score Baseline" (score 5.0) in /trending — no comment_insert hook recomputes the score today (audit §2 Scenario 4 / §4 Rec 2), so the order is unchanged before F implements the fix.',
    );
  }

  /**
   * Advisory-hold check (brief instruction): even if the render-order
   * assertion above turns out to be environment-fragile, this test pins
   * the underlying INVALIDATION SIGNAL directly — the `do_discovery_hot_score`
   * row for the commented node must actually change value after a comment,
   * independent of Views rendering. This is the "assert the invalidation
   * happens even if render-order isn't wired yet" fallback the brief
   * anticipates, kept as a SEPARATE test (not a duplicate) because it pins
   * the DATA-LAYER contract while the test above pins the
   * VIEWER-OBSERVABLE contract — both are genuinely distinct behaviors this
   * fix must deliver.
   */
  public function testCommentInsertRecomputesHotScoreRowWithoutCron(): void {
    $node = $this->createScoredForumNode('Score Recompute Target', 0.0);

    $account = $this->drupalCreateUser(['access comments', 'post comments', 'skip comment approval']);
    \Drupal::currentUser()->setAccount($account);
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'comment',
      'comment_type' => 'comment',
      'subject' => 'A reply',
      'comment_body' => ['value' => 'A reply body.', 'format' => 'plain_text'],
      'uid' => $account->id(),
      'status' => CommentInterface::PUBLISHED,
    ]);
    $comment->save();

    // No cron invocation anywhere in this test.
    $score = \Drupal::database()->select('do_discovery_hot_score', 'h')
      ->fields('h', ['score'])
      ->condition('h.nid', $node->id())
      ->execute()
      ->fetchField();

    $this->assertNotFalse($score, 'A do_discovery_hot_score row exists for the commented node.');
    $this->assertGreaterThan(
      0.0,
      (float) $score,
      'Fix 2: posting a comment must recompute that node\'s hot_score WITHOUT a cron run — today the score stays at its seeded baseline (0.0) until the next cron cycle (audit §2 Scenario 4).',
    );
  }

}
