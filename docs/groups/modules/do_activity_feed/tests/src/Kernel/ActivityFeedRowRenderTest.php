<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity_feed\Kernel;

/**
 * End-to-end Twig render of each of the three row-shape theme hooks (#129).
 *
 * U's Phase 8 rework report ("Defect 2"): every request to `/activity` (and
 * `/activity/group/{gid}`) 500'd once the view was installed — a
 * `TypeError: preg_match(): Argument #2 ($subject) must be of type string,
 * Drupal\Core\Field\FieldItemList given` from `TwigExtension::getPath()`,
 * traced to `path('entity.user.canonical', {'user': row.actor.id})` in all
 * three row templates: Twig's magic attribute getter on a ContentEntity
 * returns the `id` FIELD (a FieldItemList), not the scalar id the Symfony
 * URL generator's `preg_match()` requires.
 *
 * None of ActivityFeedRenderTest/ActivityAggregationTest/
 * ActivityCommentSnippetTest/ActivityViewsFilterTest ever pushed a row
 * through the actual Twig render pipeline — they assert on the PLAIN
 * row-model array `ActivityFeedController::renderFeed()` returns, which is
 * exactly why this class of crash went undetected through 12/12 kernel
 * GREEN. This suite closes that specific gap: it renders each themed
 * sub-render-array (`#theme => activity_row_social|content|aggregated,
 * '#row' => $row`) through the REAL renderer, exactly as
 * DoActivityFeedHooks::preprocessActivityFeed() wires it for
 * activity-feed.html.twig's `{{ row }}` print statement — this is the one
 * path that would have caught Defect 2, and the one this class exists to
 * regress.
 *
 * A second, closely-related defect surfaced only once THIS suite actually
 * rendered a row: `row.group.label` (bare magic-attribute access, no method-
 * call parens) hits the IDENTICAL FieldItemList trap as `.id` did — Group
 * entities carry a real `label` base field (confirmed empirically: `$group
 * ->label` returns a FieldItemList; only `$group->label()` returns the
 * scalar string) — fixed in the same three templates alongside the actor/
 * group URL fix, since it is the same defect class in the same files (not a
 * separate/new scope).
 *
 * Mirrors do_streams' own StreamsShellTest::testNoHardcodedRoutePathsIn...()
 * — a genuine `$renderer->renderRoot($build)` + rendered-HTML assertion —
 * for the same "assert what actually paints" reason survey.md's Testing
 * approach item 7 states.
 *
 * S's Phase 9 spec audit (decisions.md, "S — Phase 9 REWORK") found two
 * FURTHER production defects this suite's original three tests (below) did
 * not catch, because none of them exercised the real controller's
 * aggregation path or the real feed shell:
 *   - Defect 1: `ActivityFeedController::buildAggregatedRow()` picked up
 *     `actor`/`group` from each bucket member's row via `??=` but never did
 *     the parallel capture for `actor_url`/`group_url` — the returned
 *     'aggregated' row array was missing both keys entirely, so
 *     `{{ row.actor_url }}`/`{{ row.group_url }}` rendered empty strings
 *     (`href=""`) on every LIVE aggregated row. The pre-existing
 *     `testAggregatedRowRendersWithoutExceptionAndLinksResolve()` test below
 *     never caught this because it manually constructs its own `$row` array
 *     (with `actor_url`/`group_url` already populated by hand) rather than
 *     calling `ActivityFeedController::buildAggregatedRow()` — it proves the
 *     TEMPLATE resolves the keys correctly, but is silent on whether the
 *     CONTROLLER ever populates them. See
 *     self::testAggregatedRowFromRealControllerHasNonEmptyActorAndGroupHrefs()
 *     for the direct regression, which drives the real controller.
 *   - Defect 2: `activity-feed.html.twig` wrapped each already-`<li>`-rooted
 *     row in an EXTRA `<li>{{ row }}</li>`, producing invalid, doubly-nested
 *     `<li><li class="row">...</li></li>` markup — a `<li>` cannot contain a
 *     `<li>`. Fixed by having the shell print `{{ row }}` bare (rows are
 *     already complete `<li>` elements) and by rooting
 *     activity-row--content.html.twig on `<li>` (matching the approved
 *     wireframe exactly — it previously rooted on `<article>`, which, once
 *     the shell's own wrapping `<li>` was removed, would have left a bare
 *     non-`<li>` child of `<ol>`). See
 *     self::testFeedShellNeverProducesNestedListItems() for the structural
 *     regression, asserting on the FULL shell render (not a single row) —
 *     the nesting only exists at the shell+row boundary, so a single-row
 *     render (as the three tests below already do) cannot see it.
 *
 * @group do_activity_feed
 * @group do_tests
 */
class ActivityFeedRowRenderTest extends ActivityFeedKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // `format_date('short')` (used by all three row templates' timestamp)
    // needs the `date_format.short` config entity — never installed by the
    // shared ActivityFeedKernelTestBase::setUp() because none of the OTHER
    // tests in this suite render through Twig at all (they assert on the
    // plain row-model array only — see this class's own docblock). Mirrors
    // do_streams' own StreamsShellTest::setUp()'s identical
    // installConfig(['system']) call, needed for the same reason.
    $this->installConfig(['system']);
  }

  /**
   * Renders a themed row sub-render-array through the real renderer.
   *
   * @param string $theme
   *   The theme hook id (e.g. 'activity_row_social').
   * @param array $row
   *   The row-model array to render.
   *
   * @return string
   *   The rendered HTML markup.
   */
  protected function renderRow(string $theme, array $row): string {
    $renderer = \Drupal::service('renderer');
    $build = [
      '#theme' => $theme,
      '#row' => $row,
    ];
    return (string) $renderer->renderRoot($build);
  }

  /**
   * A social row (activity_membership_created) renders with no exception.
   *
   * Pins the exact crash site: `activity-row--social.html.twig` lines 37/40
   * (actor_link / group_link) previously called `path('entity.user.canonical',
   * {'user': row.actor.id})` directly in Twig.
   */
  public function testSocialRowRendersWithoutExceptionAndLinksResolve(): void {
    $group = $this->createGroup();
    $actor = $this->createUser([], 'social_row_actor');
    $this->addMember($group, $actor);

    $message = $this->createMembershipMessage($actor, $group, \Drupal::time()->getRequestTime());

    /** @var \Drupal\do_activity_feed\Service\ActivityRowBuilder $rowBuilder */
    $rowBuilder = \Drupal::service('do_activity_feed.row_builder');
    $row = $rowBuilder->buildRow($message, 'social_join');
    $this->assertNotNull($row, 'Precondition: the row builds successfully.');

    $markup = $this->renderRow('activity_row_social', $row);

    $this->assertStringContainsString(
      '/user/' . $actor->id(),
      $markup,
      'The rendered social row links to the actor\'s real canonical URL (proves no TypeError was thrown and the href resolved to a real path, not a FieldItemList).'
    );
    $this->assertStringContainsString(
      '/group/' . $group->id(),
      $markup,
      'The rendered social row links to the group\'s real canonical URL.'
    );
    $this->assertStringContainsString('data-testid="activity-row-social"', $markup, 'The social row template rendered its expected root element.');
  }

  /**
   * A content-card row (activity_post_created) renders with no exception.
   *
   * Pins `activity-row--content.html.twig` line 29 (actor link in the
   * `card__meta` strip) and line 35 (group link).
   */
  public function testContentRowRendersWithoutExceptionAndLinksResolve(): void {
    $group = $this->createGroup();
    $actor = $this->createUser([], 'content_row_actor');
    $this->addMember($group, $actor);
    $node = $this->addNode($group, 'post', ['title' => 'Render test post', 'uid' => $actor->id()]);

    $message = $this->createPostMessage($actor, $group, $node, \Drupal::time()->getRequestTime());

    /** @var \Drupal\do_activity_feed\Service\ActivityRowBuilder $rowBuilder */
    $rowBuilder = \Drupal::service('do_activity_feed.row_builder');
    $row = $rowBuilder->buildRow($message, 'content_card');
    $this->assertNotNull($row, 'Precondition: the row builds successfully (the node is viewable).');

    $markup = $this->renderRow('activity_row_content', $row);

    $this->assertStringContainsString(
      '/user/' . $actor->id(),
      $markup,
      'The rendered content row links to the actor\'s real canonical URL.'
    );
    $this->assertStringContainsString(
      '/group/' . $group->id(),
      $markup,
      'The rendered content row links to the group\'s real canonical URL.'
    );
    $this->assertStringContainsString('data-testid="activity-row-content"', $markup, 'The content row template rendered its expected root element.');
  }

  /**
   * An aggregated row (>=2 posts) renders with no exception.
   *
   * Pins `activity-row--aggregated.html.twig` line 38 (actor link in the
   * `<summary>`) and line 46 (group link).
   */
  public function testAggregatedRowRendersWithoutExceptionAndLinksResolve(): void {
    $group = $this->createGroup();
    $actor = $this->createUser([], 'aggregated_row_actor');
    $this->addMember($group, $actor);

    $now = \Drupal::time()->getRequestTime();
    $node1 = $this->addNode($group, 'post', ['title' => 'Aggregated topic 1', 'uid' => $actor->id()]);
    $node2 = $this->addNode($group, 'post', ['title' => 'Aggregated topic 2', 'uid' => $actor->id()]);
    $message1 = $this->createPostMessage($actor, $group, $node1, $now - 3600);
    $message2 = $this->createPostMessage($actor, $group, $node2, $now);

    /** @var \Drupal\do_activity_feed\Service\ActivityAggregator $aggregator */
    $aggregator = \Drupal::service('do_activity_feed.aggregator');
    $buckets = $aggregator->aggregate([$message2, $message1]);
    $aggregatedBucket = current(array_filter($buckets, static fn (array $b): bool => $b['count'] >= 2));
    $this->assertNotFalse($aggregatedBucket, 'Precondition: the two posts fold into one aggregated bucket.');

    /** @var \Drupal\do_activity_feed\Service\ActivityRowBuilder $rowBuilder */
    $rowBuilder = \Drupal::service('do_activity_feed.row_builder');
    $memberRow = $rowBuilder->buildRow($message2, 'content_card');
    $this->assertNotNull($memberRow, 'Precondition: the newest member row builds successfully.');

    $row = [
      'type' => 'aggregated',
      'message_id' => (int) $message2->id(),
      'actor' => $memberRow['actor'],
      'actor_url' => $memberRow['actor_url'],
      'group' => $memberRow['group'],
      'group_url' => $memberRow['group_url'],
      'referenced_entity_type' => NULL,
      'referenced_entity_id' => NULL,
      'created' => (int) $message2->getCreatedTime(),
      'snippet' => NULL,
      'card' => NULL,
      'count' => 2,
      'template' => 'activity_post_created',
      'children' => [
        ['title' => 'Aggregated topic 2', 'url' => $node2->toUrl()->toString()],
        ['title' => 'Aggregated topic 1', 'url' => $node1->toUrl()->toString()],
      ],
    ];

    $markup = $this->renderRow('activity_row_aggregated', $row);

    $this->assertStringContainsString(
      '/user/' . $actor->id(),
      $markup,
      'The rendered aggregated row links to the actor\'s real canonical URL.'
    );
    $this->assertStringContainsString(
      '/group/' . $group->id(),
      $markup,
      'The rendered aggregated row links to the group\'s real canonical URL.'
    );
    $this->assertStringContainsString('data-testid="activity-row-aggregated"', $markup, 'The aggregated row template rendered its expected root element.');
    $this->assertStringContainsString('Aggregated topic 2', $markup, 'A child link title appears in the rendered disclosure body.');
  }

  /**
   * S's Phase 9 REWORK Defect 1: the CONTROLLER must populate the aggregated row's URLs.
   *
   * Unlike testAggregatedRowRendersWithoutExceptionAndLinksResolve() above
   * (which hand-builds its own `$row` array with `actor_url`/`group_url`
   * already populated), this test drives the exact production path a live
   * `/activity` request takes: seeds 3 same-actor/group posts that
   * ActivityAggregator folds into one bucket, calls
   * `ActivityFeedController::renderFeed('my_groups')` directly (the same
   * entry point `myGroups()` uses), and asserts the returned 'aggregated'
   * row array's `actor_url`/`group_url` keys are non-empty, non-null real
   * canonical URL strings — then renders that EXACT row through the real
   * `activity_row_aggregated` theme hook and asserts the rendered
   * `<a href="...">` attributes are non-empty (`href=""` was the live
   * failure mode S reproduced: 10/10 empty hrefs on /activity, 4/4 on
   * /activity/group/6 — a WCAG 2.4.4 fail).
   */
  public function testAggregatedRowFromRealControllerHasNonEmptyActorAndGroupHrefs(): void {
    $group = $this->createGroup();
    $actor = $this->createUser([], 'real_controller_aggregate_actor');
    $this->addMember($group, $actor);

    $now = \Drupal::time()->getRequestTime();
    $node1 = $this->addNode($group, 'post', ['title' => 'Real controller topic 1', 'uid' => $actor->id()]);
    $node2 = $this->addNode($group, 'post', ['title' => 'Real controller topic 2', 'uid' => $actor->id()]);
    $node3 = $this->addNode($group, 'post', ['title' => 'Real controller topic 3', 'uid' => $actor->id()]);
    $message1 = $this->createPostMessage($actor, $group, $node1, $now - 10 * 3600);
    $message2 = $this->createPostMessage($actor, $group, $node2, $now - 5 * 3600);
    $message3 = $this->createPostMessage($actor, $group, $node3, $now);

    // Restrict to exactly this test's own Messages — do_activity's live
    // group_relationship_insert hook fires as an uncontrolled side effect of
    // addNode()/addMember() (see ActivityFeedRenderTest's class docblock for
    // the same noise), which would otherwise stand alone as its own
    // 'social_join' row and is irrelevant to this aggregated-row assertion.
    $this->pruneHookNoiseMessages([(int) $message1->id(), (int) $message2->id(), (int) $message3->id()]);

    $this->setCurrentUser($actor);

    /** @var \Drupal\do_activity_feed\Controller\ActivityFeedController $controller */
    $controller = \Drupal::classResolver('Drupal\do_activity_feed\Controller\ActivityFeedController');
    $build = $controller->renderFeed('my_groups');
    $rows = $build['#rows'] ?? [];

    $aggregatedRow = current(array_filter($rows, static fn (array $row): bool => $row['type'] === 'aggregated'));
    $this->assertNotFalse($aggregatedRow, 'Precondition: the 3 posts fold into one aggregated row via the real controller.');
    $this->assertSame(3, $aggregatedRow['count'], 'Precondition: the aggregated row reports count=3.');

    // The direct regression for Defect 1: the CONTROLLER's own returned row
    // array (not a hand-built test fixture) must carry non-empty URL keys.
    $this->assertArrayHasKey('actor_url', $aggregatedRow, "The controller's aggregated row array has an 'actor_url' key at all.");
    $this->assertArrayHasKey('group_url', $aggregatedRow, "The controller's aggregated row array has a 'group_url' key at all.");
    $this->assertNotEmpty($aggregatedRow['actor_url'], "The controller's aggregated row 'actor_url' is a non-empty string, not NULL/''.");
    $this->assertNotEmpty($aggregatedRow['group_url'], "The controller's aggregated row 'group_url' is a non-empty string, not NULL/''.");
    $this->assertStringContainsString('/user/' . $actor->id(), $aggregatedRow['actor_url'], "The controller's aggregated row 'actor_url' resolves to the real actor.");
    $this->assertStringContainsString('/group/' . $group->id(), $aggregatedRow['group_url'], "The controller's aggregated row 'group_url' resolves to the real group.");

    // Render the EXACT row the controller produced (not a hand-built one)
    // through the real theme hook, and assert the rendered `href` attributes
    // are non-empty — the live symptom S reproduced was `<a href="">`, which
    // a mere "key exists" assertion above cannot catch on its own.
    $markup = $this->renderRow('activity_row_aggregated', $aggregatedRow);
    $this->assertStringNotContainsString('href=""', $markup, 'Neither the actor nor the group link renders an empty href="" attribute on the real controller-produced aggregated row.');
    $this->assertMatchesRegularExpression(
      '#<a href="[^"]*/user/' . preg_quote((string) $actor->id(), '#') . '[^"]*">#',
      $markup,
      'The rendered actor link has a real, non-empty href resolving to the actor.'
    );
    $this->assertMatchesRegularExpression(
      '#<a href="[^"]*/group/' . preg_quote((string) $group->id(), '#') . '[^"]*">#',
      $markup,
      'The rendered group link has a real, non-empty href resolving to the group.'
    );
  }

  /**
   * S's Phase 9 REWORK Defect 2: the full feed shell never nests `<li>`s.
   *
   * `activity-feed.html.twig` previously wrapped each already-`<li>`-rooted
   * row in an EXTRA `<li>{{ row }}</li>`, producing invalid
   * `<li><li class="row">...</li></li>` markup for social/aggregated rows.
   * This is a SHELL-level defect — none of the three single-row-render tests
   * above can see it, since they render one row's OWN theme hook directly
   * and never involve activity-feed.html.twig's `<li>` wrapper at all. This
   * test renders the FULL `#theme => activity_feed` shell (through
   * `ActivityFeedController::renderFeed()`, driving
   * `DoActivityFeedHooks::preprocessActivityFeed()` exactly as a live
   * request does) with one row of EACH shape present, and asserts:
   *   - the rendered HTML never contains a `<li>` immediately followed by
   *     another `<li>` (the doubly-nested pattern), AND
   *   - exactly 3 row-root elements appear (one `data-testid="activity-row-*"`
   *     per row, proving the `<li>` wrapping was not simply deleted for
   *     every row — content rows are now `<li>`-rooted themselves, per the
   *     wireframe, rather than losing their list-item semantics entirely).
   *     Counted via the `data-testid="activity-row-..."` attribute rather
   *     than a raw `<li` tag count, because the aggregated row's OWN
   *     `<details>` disclosure legitimately contains further plain,
   *     unclassed `<li>` elements for its `children` list
   *     (activity-row--aggregated.html.twig's
   *     `.activity-row--aggregated__children` — 2 in this fixture) — those
   *     are correct, expected markup, not a symptom of either defect, and
   *     must not be conflated with the row-root double-nesting bug.
   */
  public function testFeedShellNeverProducesNestedListItems(): void {
    $group = $this->createGroup();
    $joiner = $this->createUser([], 'shell_structure_joiner');
    $poster = $this->createUser([], 'shell_structure_poster');
    $this->addMember($group, $joiner);
    $this->addMember($group, $poster);

    $now = \Drupal::time()->getRequestTime();

    // A social row (join) — its OWN template is already <li>-rooted.
    $joinMessage = $this->createMembershipMessage($joiner, $group, $now - 20 * 3600);

    // A standalone content row (>6h from anything else, so it does not
    // aggregate) — its OWN template previously rooted on <article>, now
    // fixed to <li> (matching the wireframe).
    $standaloneNode = $this->addNode($group, 'post', [
      'title' => 'Shell structure standalone post',
      'uid' => $poster->id(),
    ]);
    $standaloneMessage = $this->createPostMessage($poster, $group, $standaloneNode, $now - 13 * 3600);

    // A run of 2 posts by the same actor, <=6h apart, folding into one
    // aggregated row — its OWN template is already <li>-rooted.
    $aggNode1 = $this->addNode($group, 'post', ['title' => 'Shell structure aggregate 1', 'uid' => $poster->id()]);
    $aggNode2 = $this->addNode($group, 'post', ['title' => 'Shell structure aggregate 2', 'uid' => $poster->id()]);
    $aggMessage1 = $this->createPostMessage($poster, $group, $aggNode1, $now - 3600);
    $aggMessage2 = $this->createPostMessage($poster, $group, $aggNode2, $now);

    $this->pruneHookNoiseMessages([
      (int) $joinMessage->id(),
      (int) $standaloneMessage->id(),
      (int) $aggMessage1->id(),
      (int) $aggMessage2->id(),
    ]);

    $this->setCurrentUser($joiner);

    /** @var \Drupal\do_activity_feed\Controller\ActivityFeedController $controller */
    $controller = \Drupal::classResolver('Drupal\do_activity_feed\Controller\ActivityFeedController');
    $build = $controller->renderFeed('my_groups');
    $rows = $build['#rows'] ?? [];
    $types = array_column($rows, 'type');
    $this->assertContains('social_join', $types, 'Precondition: a social row is present.');
    $this->assertContains('content_card', $types, 'Precondition: a standalone content row is present.');
    $this->assertContains('aggregated', $types, 'Precondition: an aggregated row is present.');
    $this->assertCount(3, $rows, 'Precondition: exactly 3 rows (one of each shape) are present, nothing extra from hook noise.');

    $renderer = \Drupal::service('renderer');
    $markup = (string) $renderer->renderRoot($build);

    $this->assertDoesNotMatchRegularExpression(
      '/<li\b[^>]*>\s*<li\b/',
      $markup,
      'No <li> is immediately followed by another <li> — the shell must never double-wrap an already-<li>-rooted row (a <li> cannot validly contain a <li>).'
    );

    $rowRootCount = preg_match_all('/<li\b[^>]*\bdata-testid="activity-row-(?:social|content|aggregated)"/', $markup);
    $this->assertSame(3, $rowRootCount, 'Exactly one <li>-rooted row per row (3 rows) — the fix removes the DOUBLE wrapping, not list-item semantics entirely; each row template still roots on <li>.');
  }

  /**
   * Deletes every Message NOT in the given explicit id list.
   *
   * Fixture cleanup for do_activity's uncontrolled group_relationship_insert
   * hook side effect (see class docblock) — removes only the Messages this
   * test did NOT itself explicitly author, never touching the Messages under
   * test. Mirrors ActivityFeedRenderTest::pruneHookNoiseMessages() exactly
   * (that method is `protected` on a sibling test class, not the shared
   * ActivityFeedKernelTestBase, so it is duplicated here under the same name
   * rather than adding a cross-test-class dependency).
   *
   * @param int[] $keepMessageIds
   *   The mids this test explicitly created and wants preserved.
   */
  protected function pruneHookNoiseMessages(array $keepMessageIds): void {
    $storage = $this->entityTypeManager->getStorage('message');
    $storage->resetCache();
    $all = $storage->loadMultiple();
    $noise = array_filter(
      $all,
      static fn ($message): bool => !in_array((int) $message->id(), $keepMessageIds, TRUE),
    );
    if ($noise) {
      $storage->delete($noise);
    }
  }

}
